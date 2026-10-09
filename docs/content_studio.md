# Content studio

For model-generated video frames, use the optional **AI-generated marketing
video (Wan)** workflow described in [ai_video_setup.md](ai_video_setup.md).
It queues hosted image-to-video generation, labels the footage and requires
review. The animated presenter described below is the separate local renderer.

Photo posts analyse the actual room photo with the same vision/histogram engine
as the listing's Improve Photo action. Exposure, colour, contrast, tilt and detail
corrections are bounded. The campaign copy gets inventory-based title, area and
price/tenure, plus a foreground mascot with ambient tone and contact/cast shadows.
Placement favours the quieter foreground. Source files and `room_images` are
unchanged. `content_posts.creative_meta` records the correction and placement.

Promo reels are 1080×1920 H.264/AAC. The mascot introduces the room, enters the
frame, gestures toward it, changes pose and reacts to benefits. Real photos get
the same touch-up; camera reveals preserve the width of landscape uploads, and
tour clips retain their motion. The model writes short narration, a presenter
action and camera direction for each scene. The closing WhatsApp invitation is
added by the application. The script and actual render duration are saved for
review. This is an animated illustrated presenter, using BeLive's supplied poses.

Install PHP GD, a TrueType font pair, FFmpeg/ffprobe and, for the offline voice,
eSpeak NG (`apt-get install php-gd ffmpeg fonts-dejavu-core espeak-ng` on Debian).
`FFMPEG_BIN`, `ESPEAK_BIN`, `PROMO_FONT_BOLD` and `PROMO_FONT_REGULAR` override paths.
`CONTENT_VIDEO_VOICE=false` disables speech. A host without eSpeak still renders
an animated captioned tour and labels the absent voice in the preview.

## Scheduling

Run `php database/migrate.php` to apply migration 048 before starting the worker.
In Content studio settings:

- **Draft from** controls when the daily AI creation batch starts.
- **Daily posting time** controls delivery in Asia/Kuala_Lumpur (UTC+8).
- **Automatically schedule daily AI posts** approves newly generated daily posts
  for that time. It is off initially, so individual review remains available.
- **On approval, default to** preselects immediate, recommended or daily delivery
  on the manual preview. Confirming a chosen time queues that post independently
  of the daily creation switch.

If creation finishes after the posting time or within ten minutes of it, the
daily slot rolls to tomorrow. Multiple platforms can share the chosen minute.
Changing daily settings affects new drafts; already queued posts retain their
approved time and can be rescheduled or cancelled in the preview.

Keep the worker running independently of website traffic:

```sh
php cron/content_worker.php --interval=30 --max=10
```

Supervise it with systemd/Supervisor or a hosting-supported worker service and restart it on
deployment. `--once` performs one delivery cycle, suitable for a task scheduled
each minute. A persistent worker also dispatches the daily creation job in a
separate process so video rendering does not delay due deliveries. The local
cloud startup script starts this worker alongside PHP and MariaDB.

That local development startup is separate from `deploy/azure/startup.sh`:
the Azure script configures Nginx/upload limits and does not start a worker.
For production provisioning, see [IT_HANDOVER.md](IT_HANDOVER.md).

On hosting without background processes, call **POST `/cron/content`** each
minute with `Authorization: Bearer <CRON_TOKEN>`. The endpoint processes delivery
and dispatches daily creation; it returns 404 without the configured token.
The existing `cron/publish_scheduled.php`, `cron/publish_retry.php` and
`cron/auto_draft_content.php --if-due` remain usable as hosting tasks.

The studio displays worker heartbeat and account connection status. A slot is
due at its selected minute, independent of database/PHP server timezone. Delivery
starts on the next worker check (normally within 30 seconds); platform uploads
and processing add their own latency. Overdue slots run after a worker restart.

Scheduled posts on an unconnected account remain queued and recheck every five
minutes. They are not marked as successfully delivered or consumed as demos.
For live posting configure the relevant Meta Page/Instagram Business/TikTok
credentials in **API credentials**. Instagram and TikTok fetch media by URL;
`APP_URL` must be an accessible public HTTPS origin. Facebook can upload local
media directly. Manual Publish retains the explicitly badged simulation mode
when no account is configured.

Worker cycles use a database-scoped lock, optimistic review versions and an
atomic per-post delivery claim. Confirmed failures retry after 10, 20, 40, 80,
160, then up to 360 minutes, stopping after eight attempts. UTC retry timestamps
avoid timezone drift. An interrupted/timed-out delivery whose outcome is unknown
requires checking the social account before an explicit retry, avoiding blind
duplicate publication. The preview exposes that confirmation step.

Run `php tests/run.php` for schema, clock, queue, retry, media render and publishing
checks. Platform HTTP tests use fake responses; these do not create public posts.
