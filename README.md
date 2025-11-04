Note: The `index.html` and `style.css` are not from me; they are from a CodePen-Project of Adam Kuhn: https://codepen.io/cobra_winfrey/pen/ygojOG

### Downtime monitor (PHP) for cloud.gruss.li

This repo adds a tiny PHP endpoint that checks `https://cloud.gruss.li/` every time it is hit and sends Telegram alerts if the site is down for more than 2 minutes. It skips a daily maintenance window from 04:20–04:25

#### Files
- `website/www/monitor.php`: main endpoint to call (e.g., `https://YOUR_DOMAIN/monitor.php`).
- `website/www/monitor.config.php.example`: copy to `monitor.config.php` and fill in.
- State file is written to `website/monitor_state.json` (one level above `www`) if writable, otherwise to `website/www/monitor_state.json`.

#### Setup
1. Copy the example config and edit values:
   ```bash
   cp website/www/monitor.config.php.example website/www/monitor.config.php
   # edit TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID
   ```
2. Deploy the `www` folder contents to your OVH hosting `www/` directory.

#### Run automatically (OVH Scheduled Task)
Create an OVH scheduled task to call the monitor every minute:
- Type: HTTP query
- URL: `https://YOUR_DOMAIN/monitor.php`
- Frequency: Every 1 minute
- Timeout: 10 seconds

This will cause the script to check reachability and manage alert state. It will:
- Alert after 120 seconds of continuous downtime (single alert per outage)
- Send a recovery message when the site is back up
- Ignore checks and reset counters during 04:20–04:25 daily

#### Test locally
Open `https://YOUR_DOMAIN/monitor.php` in your browser. You should see JSON status.

#### Notes
- Uses PHP cURL only (no external libraries). Very small footprint.
- If you prefer a different daily window, change it in `monitor.config.php`.


