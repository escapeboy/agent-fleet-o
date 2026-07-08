// writejail — a minimal Landlock launcher for the FleetQ warm-build write-jail
// (Shepherd borrow #2). It restricts the process it execs so that it may only
// WRITE under an explicit allow-list of directories, while still being able to
// READ the rest of the filesystem. Everything the agent needs to write (the
// worktree's writable-roots, its ephemeral HOME, /tmp) is passed with repeated
// --writable flags; anything else is read-only at the kernel level.
//
// Usage:
//
//	writejail --writable /path/a --writable /path/b -- <command> [args...]
//
// This is REFERENCE SOURCE. It is intentionally NOT compiled into the app image
// in this sprint — the application path (App\Infrastructure\Sandbox\WriteJail)
// stays inert until this binary is built, installed on PATH, and the launcher is
// pointed at it via EXPERIMENTS_WARM_BUILD_WRITEJAIL_LAUNCHER, then verified with
// a real kernel round-trip on the VPS (Linux >= 5.13 for Landlock ABI v1).
//
// Build: see README.md in this directory.
package main

import (
	"fmt"
	"os"
	"syscall"

	"github.com/landlock-lsm/go-landlock/landlock"
)

func main() {
	args := os.Args[1:]

	var writable []string
	var command []string
	i := 0
	for ; i < len(args); i++ {
		if args[i] == "--" {
			command = args[i+1:]
			break
		}
		if args[i] == "--writable" && i+1 < len(args) {
			writable = append(writable, args[i+1])
			i++
			continue
		}
		fatalf("writejail: unexpected argument %q", args[i])
	}

	if len(command) == 0 {
		fatalf("writejail: no command after --")
	}

	// Read the whole tree; write only under the allow-listed dirs. BestEffort so
	// that on a kernel without Landlock we degrade to running the command rather
	// than hard-failing the build (the app layer's ChangesetPolicyValidator is the
	// backstop in that case).
	rules := []landlock.Rule{landlock.RODirs("/")}
	for _, w := range writable {
		if _, err := os.Stat(w); err == nil {
			rules = append(rules, landlock.RWDirs(w))
		}
	}
	if err := landlock.V5.BestEffort().RestrictPaths(rules...); err != nil {
		fatalf("writejail: failed to apply landlock ruleset: %v", err)
	}

	bin, err := lookPath(command[0])
	if err != nil {
		fatalf("writejail: %v", err)
	}
	if err := syscall.Exec(bin, command, os.Environ()); err != nil {
		fatalf("writejail: exec %q failed: %v", bin, err)
	}
}

func lookPath(name string) (string, error) {
	if len(name) > 0 && (name[0] == '/' || name[0] == '.') {
		return name, nil
	}
	for _, dir := range splitPath(os.Getenv("PATH")) {
		candidate := dir + "/" + name
		if fi, err := os.Stat(candidate); err == nil && !fi.IsDir() {
			return candidate, nil
		}
	}
	return "", fmt.Errorf("%q not found in PATH", name)
}

func splitPath(p string) []string {
	var out []string
	cur := ""
	for _, r := range p {
		if r == ':' {
			out = append(out, cur)
			cur = ""
			continue
		}
		cur += string(r)
	}
	if cur != "" {
		out = append(out, cur)
	}
	return out
}

func fatalf(format string, a ...any) {
	fmt.Fprintf(os.Stderr, format+"\n", a...)
	os.Exit(127)
}
