# Generative AI marketing video — hosted Wan setup

This optional integration uses the community-hosted
[Wan2.1 Fast Space](https://huggingface.co/spaces/multimodalart/wan2-1-fast)
through the official Gradio client. Wan generates **new video frames**, including
movement of the supplied BeLive mascot, from a polished real room photo. The app
adds verified copy, narration when available, original room shots and a closing
WhatsApp invitation, producing an H.264/AAC 9:16 marketing reel.

The existing **Animated room tour** remains a separate FFmpeg option. The new
**AI-generated marketing video (Wan)** option uses the hosted image-to-video
model. Generated footage is not evidence of the physical room: a model may
change furniture, room layout or character details. Every result is a labelled
draft requiring human review, rather than automatically approved daily content.

## Free allowance and deployment limits

Hugging Face documents free use of existing ZeroGPU Spaces. As of the
documentation checked on 9 October 2026, the included GPU-time allowance is two
minutes for anonymous sessions and five minutes for a free personal account.
Quotas reset 24 hours after first use, not necessarily at local midnight.
Availability, queues, supported API signatures and quotas can change. A Space
may be unavailable, and a single generation can exhaust the allowance.

This connector calls only an explicitly supported Space, requests one short generated
shot per video and never switches to another provider. It uses explicit
anonymous access when no token is supplied. Optional tokens are checked against
account metadata; PRO, organization or unrecognised account plans are rejected
because paid accounts can automatically consume prepaid ZeroGPU credits. Use a
free personal account. This is not an unlimited or guaranteed production service.

The video-model allowance is separate from existing text/vision AI API charges,
Azure hosting, storage and bandwidth. A free model does not make those free.
The Python connector runs on CPU; the GPU is supplied by the hosted Space.
The official self-hosted Wan2.2 5B example requires at least 24 GB GPU VRAM;
running it locally would need another NVIDIA GPU host and a different worker
integration. The current Azure PHP App Service has no such GPU.

References:
[ZeroGPU usage](https://huggingface.co/docs/hub/spaces-zerogpu),
[Wan2.2 model and license](https://github.com/Wan-Video/Wan2.2).

## Install on the application host

Requirements: supported PHP with GD and PostgreSQL/MySQL PDO, FFmpeg/ffprobe,
fonts and optional eSpeak NG, plus Python 3.10+ and uv. PHP must allow
`proc_open` and the generation service needs writable private `storage/ai_video`
and public rendered-video storage. Run the worker as the same application
account that owns its files. No PyTorch or large model-weight download is
required for this hosted client.

From the checkout, or `/home/site/wwwroot` in Azure SSH:

```bash
uv sync --project ai_video/huggingface --frozen
php database/migrate.php
```

The lockfile pins Gradio client 2.7.2 and its dependencies. This feature adds
MySQL migration `049_ai_video_jobs.sql` or PostgreSQL migration
`003_ai_video_jobs.sql`. Existing migrations are immutable and unchanged.
Deploy the new code before running migrations. The older backup import already
performed does not need to be repeated.

On Azure, configure these **App settings**:

```dotenv
AI_VIDEO_PROVIDER=huggingface
HF_VIDEO_SPACE=multimodalart/wan2-1-fast
HF_VIDEO_API_NAME=
HF_VIDEO_TOKEN=
MOCK_AI=false
```

On 9 October 2026, the previously configured `Wan-AI/Wan-2.2-5B` returned
HTTP 401 and was absent from the owner's public Space list. The replacement
Space was running on ZeroGPU, and its live `/generate_video` metadata check
passed anonymously. It uses `Wan-AI/Wan2.1-I2V-14B-480P-Diffusers` with CausVid
LoRA, not Wan2.2. The connector requests four steps, 3.3 seconds and a 480×832
portrait clip. Clear an old `HF_VIDEO_API_NAME` or set it to `/generate_video`.
No GPU generation was performed as part of that metadata check, so successful
generation and remaining quota still need validation on Azure. The old Space
remains explicitly selectable if access returns; no automatic fallback occurs.

Leave the token blank for the anonymous allowance, or enter a free personal
account's token privately. Do not post it in chat, screenshots, Git or command
arguments. The endpoint is discovered from the Space's image+prompt API schema.
If discovery is ambiguous, IT must inspect its **Use via API** page and set the
matching `HF_VIDEO_API_NAME`; unknown required inputs fail rather than being
guessed. The model Space and its API must be reachable from the running host.

Perform a metadata check without submitting a GPU job:

```bash
ai_video/huggingface/.venv/bin/python ai_video/huggingface/client.py --check
```

For this direct diagnostic, provide the same optional token in its private
process environment. The app passes its private configuration to the client
when launching a real generation. Expected output includes `"ok": true` and
the discovered API name. A provider error here means the live connection is
not ready, even if package installation succeeded.

Permit outbound HTTPS to `huggingface.co`, the Space's `*.hf.space` host and its
required output CDN. Keep SSL verification enabled. App data, provider keys and
tenant identities are not included in the model request; the room/mascot image
and scene prompt are uploaded to Hugging Face. Obtain approval for using room
media on this external service and avoid photos showing tenants or documents.

## Background generation

The studio creates a private job record and returns immediately. A detached
worker starts if the host supports it. The existing content worker and
authenticated `/cron/content` scheduler also dispatch waiting jobs.

For reliable managed hosting, supervise a separate generation worker:

```bash
php cron/ai_video_worker.php
```

Or run this as a scheduled hosting task:

```bash
php cron/ai_video_worker.php --once
```

Use the same service-account/configuration approach as the content worker in
[IT_HANDOVER.md](IT_HANDOVER.md). Generation has a bounded wait of roughly
10 minutes. A database lock and atomic state change prevent simultaneous claims
and repeat processing. Do not leave an SSH terminal as the production supervisor.
Recreate the virtualenv after deployments/Python changes. The Azure startup
script and GitHub PHP build do not install or supervise this client automatically.

## Use in Content studio

1. Add a real room photo; placeholder SVG artwork is unsuitable.
2. Open **Content studio → Generate post**.
3. Select the room, platform and **AI-generated marketing video (Wan)**.
4. Submit a marketing brief. The system queues the request and shows its state.
5. Refresh the studio to see `queued`, `running`, `completed` or `failed`.
6. Open **Review generated video** when ready. Check the mascot, layout,
   furnishings, pricing, speech and AI disclosure.
7. Approve and schedule the reviewed video using the existing posting controls.

One short generated introduction is followed by original room media and a CTA.
If the script-writing text model returns a 429 rate limit, the app uses a
labelled inventory template for scenes and captions and still queues Wan
generation. It does not retry the text request or switch providers. Custom
script wording from the admin brief is not applied in this case; the interaction
log records that limitation. Prices retain their tenure and benefits come from
the room's saved amenities. Other text-provider failures remain errors.
This bounds free-GPU usage and preserves verified photography for the room
presentation. An illustrated mascot is not pasted a second time over the
model-generated presenter. Photos in the room gallery are never overwritten.

Quota failures, changed APIs, invalid output and timeouts are recorded without
creating a fake successful video. No failed job is automatically retried and no
partial post is automatically published. After a timeout the remote outcome may
be unknown; inspect the Space before submitting a new request. A killed worker
can leave a job `running`: IT should inspect it, not blindly reset/resubmit it.
Final files are saved only after local decoding/rendering succeeds, and post/job
completion is committed together. Intermediate room frames and downloads remain
private under `storage/ai_video`; include them in a reviewed retention/cleanup
policy after completed/failed jobs are no longer needed.

## Validation and current limit

The client can be tested without network/GPU use:

```bash
python3 -m unittest discover -s ai_video/huggingface -p 'test_*.py'
```

The PHP suite covers quota failure, no automatic retry, cache containment,
completion, draft-only creation, disclosure and the real final FFmpeg render
using an offline clip fixture. This verifies application integration, not the
generative model's visual quality. Run database suites only against local
throwaway databases, as described in the IT guide.

The development cloud workspace could install the client but could not reach
Hugging Face's Space endpoints. A live model-generated sample and current Space
API compatibility still require the metadata check and an approved generation
on Azure. Do not treat offline fixture footage as a Wan-generated sample.
