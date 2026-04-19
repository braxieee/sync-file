# File Sync — Server ↔ On-Premise Client

A **Laravel** solution for pulling ~100MB files from on-premise clients that sit behind NAT/firewalls.

---

## Flow

1. **Admin triggers download request** via CLI or API call
2. Server creates a `Download` record with `status = pending`
3. Client's polling loop calls `GET /api/pending`
4. Server returns the job; client marks it `uploading` and streams the file
5. Server receives the stream, stores to disk, marks job `completed`
6. Download request status can be check via `GET /api/requested-files/{id}`

---

## Server Setup

### 1. Install & configure

```bash
cd server/
composer install
cp .env.example .env
php artisan key:generate
# Edit .env: set DB credentials, APP_URL
php artisan migrate
```

### 2. Register a client location

```bash
php artisan client:register "Restaurant Kuala Lumpur #1"
```

### 3. Trigger a file download

**Via CLI:**
```bash
# Execute once
php artisan file:download-request {client_id}

# Wait for completion
php artisan file:request-download {client_id} --wait

# Custom timeout (seconds)
php artisan file:request-download {client_id} --wait --timeout=600
```

**Via API:**
```bash
curl -X POST https://your-server.com/api/request-file \
  -H "Content-Type: application/json" \
  -d '{"client_id": 1}'
```

---

## Client Setup (On-Premise)

### 1. Install

```bash
cd client/
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Configure `.env`

```dotenv
SERVER_URL=https://your-server.com
CLIENT_API_TOKEN= # token from client:register
SYNC_FILE_PATH=/path/to/your/100mb/file.db
POLL_INTERVAL=15
```

### 3. Run the poller

**Test**
```bash
php artisan client:poll
```
