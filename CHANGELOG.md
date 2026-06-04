# Changelog - Taalkic

## dev
- Graceful production reload via `make reload` that picks up route, callback, and template changes without dropping requests
- Close idle keep-alive connections on worker stop so a graceful reload is not stalled by the keep-alive timeout
