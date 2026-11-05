package redis

import goredis "github.com/redis/go-redis/v9"

// Nil re-exports go-redis's sentinel error so callers outside this package
// don't need to import go-redis directly.
var Nil = goredis.Nil