# Terminal demo at the top of the README, recorded with VHS
# (https://github.com/charmbracelet/vhs). Every command below runs for real
# against the local docker compose stack. To regenerate docs/demo/demo.gif:
#
#   docker compose up -d --wait
#   docker build -t integrations-kit-vhs docs/demo
#   docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$PWD:/vhs" integrations-kit-vhs docs/demo/demo.tape

Output docs/demo/demo.gif

Require docker

Set Shell "bash"
Set FontSize 16
Set Width 1600
Set Height 900
Set Padding 20
Set Theme "Catppuccin Mocha"
Set WindowBar Colorful
Set TypingSpeed 10ms
Set Framerate 24

Hide
Type "export PS1='$ ' && bash docs/demo/prepare.sh && clear" Enter
Wait@180s /\$\s*$/
Show

Type@18ms "# Deliver every day of the last three that is still missing" Enter
Type "docker compose exec app php artisan report:deliver --sync" Enter
Wait@60s /\$\s*$/
Sleep 1s

Type@18ms "# Three deliveries, a single OAuth token request (WireMock's journal)" Enter
Type `docker compose exec app curl -s -w '\n' -d '{"url":"/oauth/token"}' partner-api:8080/__admin/requests/count` Enter
Wait /\$\s*$/
Sleep 1s

Type@18ms "# Complete files in the partner's SFTP inbox, no .part left behind" Enter
Type "docker compose exec sftp ls -l /home/partner/inbound" Enter
Wait /\$\s*$/
Sleep 1s

Type@18ms "# The SFTP server starts refusing writes: retried with backoff, then a clear failure" Enter
Type "docker compose exec sftp chmod 555 /home/partner/inbound" Enter
Wait /\$\s*$/
Type "docker compose exec app php artisan report:deliver --date=2026-10-30 --sync" Enter
Wait@90s /\$\s*$/
Sleep 1s

Type@18ms "# Writable again: the retry resumes at the SFTP step and reuses the export" Enter
Type "docker compose exec sftp chmod 755 /home/partner/inbound" Enter
Wait /\$\s*$/
Type "docker compose exec app php artisan report:deliver --date=2026-10-30 --sync" Enter
Wait@60s /\$\s*$/
Type "docker compose exec app php artisan report:status" Enter
Wait /\$\s*$/
Sleep 2.5s

Type@18ms "# Running it again changes nothing" Enter
Type "docker compose exec app php artisan report:deliver --sync" Enter
Wait@60s /\$\s*$/
Sleep 3s
