"""One hosted image-to-video call. stdin/stdout protocol; no provider error leaks."""
from __future__ import annotations

import contextlib
import json
import os
from pathlib import Path
import re
import sys


IMAGE_NAMES = {"image", "input_image", "start_image", "start_frame", "first_frame", "init_image"}
PROMPT_NAMES = {"prompt", "text_prompt", "positive_prompt"}
DEFAULT_SPACE = "multimodalart/wan2-1-fast"
SUPPORTED_SPACES = {
    DEFAULT_SPACE: "Wan2.1-I2V-14B-480P + CausVid LoRA",
    "Wan-AI/Wan-2.2-5B": "Wan2.2-TI2V-5B",
}


def select_endpoint(info: dict, requested: str = "") -> tuple[str, list[dict]]:
    candidates = []
    for name, endpoint in info.get("named_endpoints", {}).items():
        params = endpoint.get("parameters", [])
        names = {p.get("parameter_name", "") for p in params}
        if names & IMAGE_NAMES and names & PROMPT_NAMES and (not requested or name == requested):
            candidates.append((name, params))
    if len(candidates) != 1:
        raise ValueError("endpoint")
    return candidates[0]


def arguments(params: list[dict], image: object, prompt: str, space: str = "Wan-AI/Wan-2.2-5B") -> dict:
    # Use the server's declared defaults, overriding only recognised controls.
    overrides = {"seed": 42, "randomize_seed": False, "num_frames": 81,
                 "frame_num": 81, "num_inference_steps": 20, "steps": 20,
                 "use_prompt_extend": False, "prompt_extend": False}
    if space == DEFAULT_SPACE:
        # This distilled model uses four steps. Its two-second API default is
        # too short for the application's minimum 2.5-second introduction.
        overrides.update(steps=4, duration_seconds=3.3, height=832, width=480)
    result = {}
    for param in params:
        name = param.get("parameter_name", "")
        if name in IMAGE_NAMES:
            result[name] = image
        elif name in PROMPT_NAMES:
            result[name] = prompt[:1800]
        elif name in overrides:
            result[name] = overrides[name]
        elif not param.get("parameter_has_default", False):
            raise ValueError("arguments")
    return result


def find_video(value: object, cache: Path) -> Path:
    found = []
    def visit(item):
        if isinstance(item, str):
            path = Path(item).resolve()
            if path.suffix.lower() == ".mp4" and path.is_file() and path.is_relative_to(cache.resolve()):
                if 1024 <= path.stat().st_size <= 200 * 1024 * 1024:
                    found.append(path)
        elif isinstance(item, dict):
            for child in item.values():
                visit(child)
        elif isinstance(item, (tuple, list)):
            for child in item:
                visit(child)
    visit(value)
    if not found:
        raise ValueError("output")
    return found[0]


def check_free_account(token: str, api):
    if token:
        # ZeroGPU can automatically consume prepaid credits on paid accounts.
        # Missing/unrecognised billing metadata is also rejected conservatively.
        account = api.whoami(token=token)
        if account.get("type") != "user" or account.get("isPro") is not False:
            raise ValueError("paid_account")


def run(payload: dict, factory=None, api=None, file_handler=None) -> dict:
    if factory is None:
        from gradio_client import Client, handle_file
        from huggingface_hub import HfApi
        factory, api, file_handler = Client, HfApi(), handle_file
    space = os.environ.get("HF_VIDEO_SPACE") or DEFAULT_SPACE
    if space not in SUPPORTED_SPACES:
        raise ValueError("space")  # Explicit supported hosts only; no automatic provider fallback.
    token = os.environ.get("HF_VIDEO_TOKEN", "")
    check_free_account(token, api)
    image = Path(payload.get("image", "")).resolve()
    cache = Path(payload.get("cache", os.getcwd())).resolve()
    if not payload.get("check") and (not image.is_file() or image.suffix.lower() != ".png" or not cache.is_dir()):
        raise ValueError("input")
    with contextlib.redirect_stdout(sys.stderr):
        client = factory(space, token=token if token else False, verbose=False, download_files=str(cache),
                         ssl_verify=True, analytics_enabled=False, httpx_kwargs={"timeout": 30})
        info = client.view_api(return_format="dict", print_info=False)
        requested = os.environ.get("HF_VIDEO_API_NAME", "") or ("/generate_video" if space == DEFAULT_SPACE else "")
        name, params = select_endpoint(info, requested)
        if payload.get("check"):
            arguments(params, None, "Metadata check only.", space)
            return {"ok": True, "space": space, "api_name": name}
        values = arguments(params, file_handler(str(image)), str(payload["prompt"]), space)
        job = client.submit(api_name=name, **values)
        try:
            result = job.result(timeout=600)
        except TimeoutError:
            job.cancel()
            raise ValueError("timeout") from None
    video = find_video(result, cache)
    return {"ok": True, "video": str(video), "space": space,
            "model": SUPPORTED_SPACES[space], "api_name": name}


def main():
    try:
        payload = {"check": True} if "--check" in sys.argv else json.load(sys.stdin)
        result = run(payload)
    except Exception as exc:
        known = {"endpoint", "arguments", "output", "paid_account", "space", "input", "timeout"}
        text = str(exc).lower()
        code = str(exc) if isinstance(exc, ValueError) and str(exc) in known else (
            "quota" if any(word in text for word in ("quota", "daily limit", "gpu limit")) else "provider")
        # Error text may contain tokens/URLs or private paths. Emit only a code.
        result = {"ok": False, "error": code}
        if "--check" in sys.argv:
            # Safe diagnostics: never emit exception messages, headers or URLs.
            result["exception_type"] = type(exc).__name__
            response = getattr(exc, "response", None)
            status = getattr(response, "status_code", None)
            if isinstance(status, int):
                result["http_status"] = status
    sys.stdout.write(json.dumps(result) + "\n")
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
