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
        args = client.arguments(params, "image", "prompt", client.FAST_SPACE)
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
        with patch.dict("os.environ", {"HF_VIDEO_TOKEN": "", "HF_VIDEO_API_NAME": "", "HF_VIDEO_SPACE": client.FAST_SPACE}):
            result = client.run({"check": True}, factory=Gradio)
        self.assertEqual(result, {"ok": True, "space": client.FAST_SPACE, "api_name": "/generate_video"})

    def test_two_frame_profile_uses_same_anchor_and_eight_steps(self):
        params = [parameter(n, True) for n in ("start_image_pil", "end_image_pil", "prompt", "steps", "duration_seconds")]
        anchor = object()
        args = client.arguments(params, anchor, "Animate", client.DEFAULT_SPACE)
        self.assertIs(args['start_image_pil'], anchor)
        self.assertIs(args['end_image_pil'], anchor)
        self.assertEqual(args['steps'], 8)
        self.assertEqual(args['duration_seconds'], 3.3)

    def test_two_frame_metadata_never_calls_end_frame_generator(self):
        class Gradio:
            def __init__(self, *args, **kwargs): pass
            def view_api(self, **kwargs):
                params = [parameter(n) for n in ("start_image_pil", "end_image_pil", "prompt")]
                return {"named_endpoints": {"/generate_video": {"parameters": params},
                    "/generate_video_1": {"parameters": params}, "/lambda": {"parameters": [parameter("img")]}}}
            def submit(self, **kwargs): raise AssertionError("GPU work in metadata check")
        with patch.dict("os.environ", {"HF_VIDEO_TOKEN": "", "HF_VIDEO_API_NAME": "", "HF_VIDEO_SPACE": client.DEFAULT_SPACE}):
            result = client.run({'check': True}, factory=Gradio)
        self.assertEqual(result['api_name'], '/generate_video')

    def test_two_frame_fake_boundary_submits_once_with_model_attribution(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); image = root / 'room.png'; image.write_bytes(b'png')
            cache = root / 'cache'; cache.mkdir()
            video = cache / 'out.mp4'; video.write_bytes(b'x' * 2048)
            requests = []
            class Job:
                def result(self, timeout): return (str(video), 42)
            class Gradio:
                def __init__(self, *args, **kwargs): pass
                def view_api(self, **kwargs):
                    return {'named_endpoints': {'/generate_video': {'parameters': [
                        parameter(n, n in ('steps', 'duration_seconds')) for n in
                        ('start_image_pil', 'end_image_pil', 'prompt', 'steps', 'duration_seconds')]}}}
                def submit(self, **kwargs): requests.append(kwargs); return Job()
            with patch.dict('os.environ', {'HF_VIDEO_TOKEN': '', 'HF_VIDEO_API_NAME': '', 'HF_VIDEO_SPACE': client.DEFAULT_SPACE}):
                result = client.run({'image': str(image), 'cache': str(cache), 'prompt': 'Wave gently'},
                    factory=Gradio, file_handler=lambda path: {'path': path})
            self.assertEqual(len(requests), 1)
            self.assertIs(requests[0]['start_image_pil'], requests[0]['end_image_pil'])
            self.assertTrue(requests[0]['prompt'].startswith('Start and finish'))
            self.assertEqual(result['model'], 'Wan2.2-I2V-A14B (first/last-frame)')

    def test_provider_runtime_diagnostic_does_not_leak_messages(self):
        class AppError(ValueError): pass
        exc = client.ProviderFailure('result', AppError('RuntimeError hf_PRIVATE /private/room.png'))
        result = client.failure_result(exc)
        self.assertEqual(result, {'ok': False, 'error': 'provider_runtime', 'stage': 'result', 'exception_type': 'AppError'})
        self.assertNotIn('PRIVATE', str(result))
        self.assertNotIn('room.png', str(result))

    def test_quota_and_http_auth_are_distinct_from_runtime_errors(self):
        class AppError(ValueError): pass
        self.assertEqual(client.failure_result(client.ProviderFailure('result', AppError('You have exceeded your ZeroGPU quota')))['error'], 'quota')
        class HTTPError(Exception): pass
        exc = HTTPError('private provider error')
        from types import SimpleNamespace
        exc.response = SimpleNamespace(status_code=401)
        result = client.failure_result(client.ProviderFailure('submit', exc))
        self.assertEqual(result['error'], 'authentication')
        self.assertEqual(result['stage'], 'submit')
        self.assertEqual(result['http_status'], 401)

    def test_connection_failure_records_stage_without_submission(self):
        def factory(*args, **kwargs): raise ConnectionError('hf_PRIVATE provider connection failed')
        with patch.dict("os.environ", {"HF_VIDEO_TOKEN": "", "HF_VIDEO_SPACE": client.DEFAULT_SPACE}):
            try:
                client.run({'check': True}, factory=factory)
                self.fail('Connection unexpectedly passed')
            except client.ProviderFailure as exc:
                result = client.failure_result(exc)
        self.assertEqual(result['stage'], 'connect')
        self.assertEqual(result['exception_type'], 'ConnectionError')
        self.assertNotIn('PRIVATE', str(result))

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
