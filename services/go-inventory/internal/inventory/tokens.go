package inventory

import (
	"crypto/rand"
	"time"

	"github.com/oklog/ulid/v2"
)

// newToken returns a new ULID token. ULIDs are lexicographically sortable by
// time, which makes them great for tokens (recent tokens sort last).
func newToken() string {
	entropy := ulid.Monotonic(rand.Reader, 0)
	return ulid.MustNew(ulid.Timestamp(time.Now()), entropy).String()
}