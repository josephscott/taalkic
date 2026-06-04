# Changelog - Taalkic

## dev
- Graceful production reload via `make reload` that picks up route, callback, and template changes without dropping requests
- Close idle keep-alive connections on worker stop so a graceful reload is not stalled by the keep-alive timeout
- Return a 500 ( not a blank 200 ) when a route's callback file is missing, fall back to the plain error page for a missing error-handler file, and log missing files when routes load
