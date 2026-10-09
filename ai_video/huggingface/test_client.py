import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("video_client", Path(__file__).with_name("client.py"))
client = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client)


def parameter(name, default=False):
    return {"parameter_name": name, "parameter_has_default": default}


class ClientTest(unittest.TestCase):
    def test_selects_image_to_video_not_text_to_video(self):
        info = {"named_endpoints": {
            "/text": {"parameters": [parameter("prompt")]},
            "/generate": {"parameters": [parameter("input_image"), parameter("prompt")]}}}
        self.assertEqual(client.select_endpoint(info)[0], "/generate")

    def test_ambiguous_endpoints_require_configuration(self):
        endpoint = {"parameters": [parameter("image"), parameter("prompt")]}
        with self.assertRaisesRegex(ValueError, "endpoint"):
            client.select_endpoint({"named_endpoints": {"/a": endpoint, "/b": endpoint}})
        self.assertEqual(client.select_endpoint({"named_endpoints": {"/a": endpoint, "/b": endpoint}}, "/b")[0], "/b")

    def test_unknown_required_arguments_are_not_guessed(self):
        with self.assertRaisesRegex(ValueError, "arguments"):
            client.arguments([parameter("billing_account")], "image", "prompt")

    def test_fast_model_has_short_portrait_profile(self):
        params = [parameter(n, True) for n in ("steps", "duration_seconds", "height", "width", "randomize_seed")]
        args = client.arguments(params, "image", "prompt", client.DEFAULT_SPACE)
        self.assertEqual(args, {"steps": 4, "duration_seconds": 3.3, "height": 832, "width": 480, "randomize_seed": False})

    def test_live_fast_schema_is_accepted_without_gpu_work(self):
        class Gradio:
            def __init__(self, *args, **kwargs): pass
            def view_api(self, **kwargs):
                return {"named_endpoints": {"/generate_video": {"parameters": [
                    parameter("input_image"), parameter("prompt", True),
                    *[parameter(n, True) for n in ("height", "width", "negative_prompt", "duration_seconds", "guidance_scale", "steps", "seed", "randomize_seed")]
                ]}}}
            def submit(self, **kwargs): raise AssertionError("GPU submission during metadata check")
        with patch.dict("os.environ", {"HF_VIDEO_TOKEN": "", "HF_VIDEO_API_NAME": "", "HF_VIDEO_SPACE": client.DEFAULT_SPACE}):
            result = client.run({"check": True}, factory=Gradio)
        self.assertEqual(result, {"ok": True, "space": client.DEFAULT_SPACE, "api_name": "/generate_video"})

    def test_arbitrary_space_does_not_trigger_provider_fallback(self):
        with patch.dict("os.environ", {"HF_VIDEO_SPACE": "unknown/paid-space"}):
            with self.assertRaisesRegex(ValueError, "space"):
                client.run({"check": True}, factory=lambda *a, **k: self.fail("Unexpected connection"))

    def test_bounds_prompt_and_disables_paid_prompt_extension(self):
        args = client.arguments([parameter("image"), parameter("prompt"), parameter("use_prompt_extend", True),
                                 parameter("num_frames", True)], "input", "x" * 5000)
        self.assertEqual(len(args["prompt"]), 1800)
        self.assertEqual(args["num_frames"], 81)
        self.assertFalse(args["use_prompt_extend"])

    def test_output_cannot_escape_the_download_cache(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            cache = root / "cache"; cache.mkdir()
            outside = root / "external.mp4"; outside.write_bytes(b"x" * 2048)
            with self.assertRaisesRegex(ValueError, "output"):
                client.find_video(str(outside), cache)
            output = cache / "out.mp4"; output.write_bytes(b"x" * 2048)
            self.assertEqual(client.find_video(({"video": str(output)}, 42), cache), output)

    def test_paid_and_unknown_plans_are_blocked(self):
        class API:
            def __init__(self, data): self.data = data
            def whoami(self, **kwargs): return self.data
        for data in ({"type": "user", "isPro": True}, {"type": "user"}, {"type": "org", "isPro": False}):
            with self.assertRaisesRegex(ValueError, "paid_account"):
                client.check_free_account("token", API(data))
        client.check_free_account("token", API({"type": "user", "isPro": False}))
        client.check_free_account("", None)

    def test_protocol_with_fake_gradio_boundary(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); image = root / "in.png"; image.write_bytes(b"png")
            cache = root / "cache"; cache.mkdir()
            video = cache / "generated.mp4"; video.write_bytes(b"x" * 2048)
            recorded = {}
            class Job:
                def result(self, timeout): return (str(video), 42)
            class Gradio:
                def __init__(self, space, **kwargs): recorded.update(kwargs)
                def view_api(self, **kwargs):
                    return {"named_endpoints": {"/generate": {"parameters": [parameter("image"), parameter("prompt")]}}}
                def submit(self, **kwargs): recorded.update(kwargs); return Job()
            with patch.dict("os.environ", {"HF_VIDEO_TOKEN": "", "HF_VIDEO_API_NAME": "", "HF_VIDEO_SPACE": "Wan-AI/Wan-2.2-5B"}):
                result = client.run({"image": str(image), "cache": str(cache), "prompt": "Animate the mascot"},
                                    factory=Gradio, file_handler=lambda value: value)
            self.assertTrue(result["ok"])
            self.assertEqual(result["model"], "Wan2.2-TI2V-5B")
            self.assertTrue(recorded["ssl_verify"])
            self.assertFalse(recorded["analytics_enabled"])
            self.assertEqual(recorded["api_name"], "/generate")
            self.assertIs(recorded["token"], False)  # No implicit cached/paid-account token.

    def test_metadata_check_never_submits_gpu_work(self):
        class Gradio:
            def __init__(self, *args, **kwargs): pass
            def view_api(self, **kwargs):
                return {"named_endpoints": {"/generate": {"parameters": [parameter("image"), parameter("prompt")]}}}
            def submit(self, **kwargs): raise AssertionError("GPU submission during metadata check")
        with patch.dict("os.environ", {"HF_VIDEO_TOKEN": "", "HF_VIDEO_API_NAME": "", "HF_VIDEO_SPACE": "Wan-AI/Wan-2.2-5B"}):
            self.assertTrue(client.run({"check": True}, factory=Gradio)["ok"])


if __name__ == "__main__": unittest.main()
