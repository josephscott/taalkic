# Changelog - Taalkic

## dev
- Enable CLI OPcache and tracing JIT when running the server, the biggest throughput win
- Production runs a frozen opcode cache and refreshes the routes, callback, and template files on reload via opcache_invalidate(), so `make reload` still picks them up with no per-request cost
- Static routes skip FastRoute via a direct method-and-path lookup built once per worker
- Cache the per-request `is_file()` check for route callbacks so a matched route does not stat the disk on every request
- Graceful production reload via `make reload` that picks up route, callback, and template changes without dropping requests
- Close idle keep-alive connections on worker stop so a graceful reload is not stalled by the keep-alive timeout
- Return a 500 ( not a blank 200 ) when a route's callback file is missing, fall back to the plain error page for a missing error-handler file, and log missing files when routes load
- `make dev` runs the server in the foreground and reloads automatically on file changes, via a new `watch` App config option
- Catch a throwing route and return a 500 instead of letting the exception stop the worker, and always close the route's output buffer so partial output cannot bleed into a later request
- Include the routes file, route callbacks, and templates from free functions instead of inside App, so they run with no class scope and cannot reach App's private statics through self::
