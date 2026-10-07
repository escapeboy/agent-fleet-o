# writejail — warm-build Landlock launcher (Shepherd borrow #2)

Reference helper that jails the `claude-code-vps` agent process so it can only
**write** under an explicit allow-list of directories (the run's writable-roots
+ its ephemeral HOME + `/tmp`), while reading the rest of the tree normally.
Enforced at the kernel via [Landlock](https://landlock.io/) (Linux ≥ 5.13).

## Status

The parent repo's `docker/php/Dockerfile` builds this launcher in a `writejail`
stage and installs it at `/usr/local/bin/writejail`. The application path
(`App\Infrastructure\Sandbox\WriteJail`) stays a no-op unless:

1. `EXPERIMENTS_WARM_BUILD_WRITEJAIL=true`, **and**
2. `EXPERIMENTS_WARM_BUILD_WRITEJAIL_LAUNCHER=/usr/local/bin/writejail`, **and**
3. the OS is Linux.

Besides the `--writable` directories, `/dev/null`, `/dev/zero`, `/dev/full` and
`/dev/tty` stay writable: node, git and shells open them for writing, and the
agent fails on its first child process without them.

## Build

```sh
cd base/docker/writejail
go mod download
CGO_ENABLED=0 go build -o /usr/local/bin/writejail .
```

## Install into the app/horizon image

Add to the Dockerfile (multi-stage; keep Go out of the final image):

```dockerfile
FROM golang:1.24-alpine AS writejail
WORKDIR /src
COPY base/docker/writejail/ .
RUN CGO_ENABLED=0 go build -o /writejail .

# ... in the final php-fpm stage:
COPY --from=writejail /writejail /usr/local/bin/writejail
```

Then set on the VPS `.env`:

```
EXPERIMENTS_WARM_BUILD_WRITEJAIL=true
EXPERIMENTS_WARM_BUILD_WRITEJAIL_LAUNCHER=/usr/local/bin/writejail
```

## Verify (required before trusting it)

The container must allow Landlock — the default Docker seccomp profile permits
the `landlock_*` syscalls on modern kernels, but confirm:

```sh
# inside the app container:
writejail --writable /tmp -- sh -c 'echo ok > /tmp/x && echo WROTE_TMP; \
  (echo nope > /usr/x 2>/dev/null && echo WROTE_USR || echo BLOCKED_USR)'
# expect: WROTE_TMP then BLOCKED_USR
```

If it prints `WROTE_USR`, Landlock is not being enforced (old kernel / blocked
syscall) — do NOT rely on the jail; the app-layer `ChangesetPolicyValidator`
(#3) remains the backstop that flags out-of-roots writes on the changeset.

## Behaviour on unsupported kernels

`landlock.V5.BestEffort()` degrades to a weaker ruleset (or none) rather than
failing, so a build never breaks because the kernel is too old. That is exactly
why enforcement must be positively verified, not assumed.
