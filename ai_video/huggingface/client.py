"""One hosted image-to-video call. stdin/stdout protocol; no provider error leaks."""
from __future__ import annotations

import contextlib
import json
import os
from pathlib import Path
import re
import sys


IMAGE_NAMES = {"image", "input_image", "start_image", "start_frame", "first_frame", "init_image"}
IMAGE_NAMES.add("start_image_pil")
END_IMAGE_NAMES = {"end_image_pil"}
PROMPT_NAMES = {"prompt", "text_prompt", "positive_prompt"}
FAST_SPACE = "multimodalart/wan2-1-fast"
DEFAULT_SPACE = "multimodalart/wan-2-2-first-last-frame"
SUPPORTED_SPACES = {
    FAST_SPACE: "Wan2.1-I2V-14B-480P + CausVid LoRA",
    DEFAULT_SPACE: "Wan2.2-I2V-A14B (first/last-frame)",
    "Wan-AI/Wan-2.2-5B": "Wan2.2-TI2V-5B",
}


class ProviderFailure(ValueError):
    def __init__(self, stage: str, cause: Exception):
        self.stage, self.cause = stage, cause
        super().__init__(str(cause))


def failure_result(exc: Exception) -> dict:
    cause = exc.cause if isinstance(exc, ProviderFailure) else exc
    stage = exc.stage if isinstance(exc, ProviderFailure) else "input"
    message = str(cause).lower()
    status = getattr(getattr(cause, "response", None), "status_code", None)
    known = {"endpoint", "arguments", "output", "paid_account", "space", "input", "timeout"}
    if isinstance(cause, ValueError) and str(cause) in known:
        code = str(cause)
    elif status == 429 or any(w in message for w in ("quota", "daily limit", "gpu limit", "rate limit")):
        code = "quota"
    elif status in (401, 403) or type(cause).__name__ == "AuthenticationError" or any(w in message for w in ("unauthorized", "unauthenticated", "must be logged in", "please log in")):
        code = "authentication"
    elif any(w in message for w in ("out of memory", "gpu unavailable", "gpu capacity")):
        code = "gpu_capacity"
    elif type(cause).__name__ == "AppError":
        code = "provider_runtime"
    elif "certificate" in message or "ssl" in type(cause).__name__.lower():
        code = "tls"
    elif "timeout" in type(cause).__name__.lower() or "timed out" in message:
        code = "timeout"
    elif status in (502, 503, 504):
        code = "provider_unavailable"
    else:
        code = "provider"
    # Fixed categories and Python class names only, never exception messages.
    result = {"ok": False, "error": code, "stage": stage,
              "exception_type": type(cause).__name__}
    if isinstance(status, int):
        result["http_status"] = status
    return result


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
    if space == FAST_SPACE:
        # This distilled model uses four steps. Its two-second API default is
        # too short for the application's minimum 2.5-second introduction.
        overrides.update(steps=4, duration_seconds=3.3, height=832, width=480)
    elif space == "multimodalart/wan-2-2-first-last-frame":
        overrides.update(steps=8, duration_seconds=3.3)
    result = {}
    for param in params:
        name = param.get("parameter_name", "")
        if name in IMAGE_NAMES or (space == "multimodalart/wan-2-2-first-last-frame" and name in END_IMAGE_NAMES):
            # One room/mascot image anchors both ends; the model animates a
            # short loop between them. No extra image-generation API is used.
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
    try:
        check_free_account(token, api)
    except Exception as exc:
        raise ProviderFailure("account", exc) from None
    image = Path(payload.get("image", "")).resolve()
    cache = Path(payload.get("cache", os.getcwd())).resolve()
    if not payload.get("check") and (not image.is_file() or image.suffix.lower() != ".png" or not cache.is_dir()):
        raise ValueError("input")
    stage = "connect"
    try:
        with contextlib.redirect_stdout(sys.stderr):
            client = factory(space, token=token if token else False, verbose=False, download_files=str(cache),
                             ssl_verify=True, analytics_enabled=False, httpx_kwargs={"timeout": 30})
            stage = "schema"
            info = client.view_api(return_format="dict", print_info=False)
            requested = os.environ.get("HF_VIDEO_API_NAME", "") or ("/generate_video" if space.startswith("multimodalart/") else "")
            name, params = select_endpoint(info, requested)
            if payload.get("check"):
                arguments(params, None, "Metadata check only.", space)
                return {"ok": True, "space": space, "api_name": name}
            prompt = str(payload["prompt"])
            if space == "multimodalart/wan-2-2-first-last-frame":
                prompt = "Start and finish in the supplied pose; animate a gentle wave and room introduction between these frames. " + prompt
            values = arguments(params, file_handler(str(image)), prompt, space)
            stage = "submit"
            job = client.submit(api_name=name, **values)
            stage = "result"
            try:
                result = job.result(timeout=600)
            except TimeoutError:
                job.cancel()
                raise ValueError("timeout") from None
        stage = "output"
        video = find_video(result, cache)
    except Exception as exc:
        raise ProviderFailure(stage, exc) from None
    return {"ok": True, "video": str(video), "space": space,
            "model": SUPPORTED_SPACES[space], "api_name": name}


def main():
    try:
        payload = {"check": True} if "--check" in sys.argv else json.load(sys.stdin)
        result = run(payload)
    except Exception as exc:
        result = failure_result(exc)
    sys.stdout.write(json.dumps(result) + "\n")
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
