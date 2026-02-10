# Shiki Backend

Laravel API for the Shiki project.

Stack
- Laravel (PHP)
- MySQL (Docker)

Quick start (Docker)
1) From repo root:
   docker compose up -d db backend
2) Run migrations:
   docker compose exec backend php artisan migrate

Ports (from docker-compose.yml)
- API: http://localhost:8082
- MySQL: localhost:8083
- phpMyAdmin: http://localhost:8084

Local (no Docker)
1) Copy .env.example -> .env and set DB_* to your local MySQL
2) composer install
3) php artisan key:generate
4) php artisan migrate
5) php artisan serve

Notes
- If you run artisan on host with Docker DB, use DB_HOST=127.0.0.1 and DB_PORT=8083 in backend/.env.

Episode video pipeline (MVP)
- Command: `php artisan anime:episode:transcode {episode_id} {source} --qualities=1080 --language=ru --overwrite`
- Example:
  `php artisan anime:episode:transcode 101 "D:\videos\ep1.mkv" --qualities=1080 --language=ru --overwrite`
- Accepted source can be `mkv/avi/mp4` (any format FFmpeg reads).
- Output files are written to:
  `storage/app/public/videos/anime/{anime_id}/s{season_number}/e{episode_number}/`
- The command updates `episode_media` rows automatically and marks the first generated quality as `is_primary=1`.
- Qualities above source resolution are skipped automatically (no upscaling).
