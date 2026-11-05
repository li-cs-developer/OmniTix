package redis

import (
	"context"

	goredis "github.com/redis/go-redis/v9"
)

// Scripts holds all Lua scripts used by the inventory engine. They're loaded
// once at startup and invoked by SHA1, which is faster than sending the full
// script every time.
type Scripts struct {
	// HoldSeatAtomic holds a seat, checks the sold set, and records it in the
	// held set — all in one atomic step.
	HoldSeatAtomic *goredis.Script

	// ReleaseSeatIfOwner removes a seat hold only if the caller owns it.
	ReleaseSeatIfOwner *goredis.Script

	// ExtendSeatIfOwner extends the TTL of a seat hold only if the caller owns it.
	ExtendSeatIfOwner *goredis.Script

	// ConfirmSeatIfOwner atomically removes the hold, removes it from the held
	// set, and adds the seat to the sold set.
	ConfirmSeatIfOwner *goredis.Script

	// HoldGaZone atomically checks GA capacity and decrements.
	HoldGaZone *goredis.Script

	// ReleaseGaIfOwner decrements the held count for a GA zone by N.
	ReleaseGaIfOwner *goredis.Script

	// ConfirmGaIfOwner atomically decrements held and increments sold for GA.
	ConfirmGaIfOwner *goredis.Script
}

// NewScripts returns the canonical set of Lua scripts for the engine.
//
// All scripts follow the same conventions:
//   - Return integers: positive = success, 0 or negative = failure/special.
//   - Never use KEYS in a loop over an unbounded set (Redis Cluster safety).
//   - Receive all keys via KEYS[] and all non-key args via ARGV[].
func NewScripts() *Scripts {
	return &Scripts{
		// ─── HoldSeatAtomic ─────────────────────────────────────
		// KEYS[1] = seat hold key
		// KEYS[2] = sold seats set
		// KEYS[3] = held seats set
		// ARGV[1] = seat_id
		// ARGV[2] = serialized hold JSON
		// ARGV[3] = TTL seconds
		//
		// Returns:
		//   1 = held
		//   0 = already sold
		//  -1 = already held
		HoldSeatAtomic: goredis.NewScript(`
			if redis.call("SISMEMBER", KEYS[2], ARGV[1]) == 1 then
				return 0
			end
			local ok = redis.call("SET", KEYS[1], ARGV[2], "EX", ARGV[3], "NX")
			if not ok then
				return -1
			end
			redis.call("SADD", KEYS[3], ARGV[1])
			return 1
		`),

		// ─── ReleaseSeatIfOwner ─────────────────────────────────
		// KEYS[1] = seat hold key
		// KEYS[2] = held set
		// ARGV[1] = expected owner user_id
		//
		// Returns: 1 if released, 0 if not owned or not present.
		ReleaseSeatIfOwner: goredis.NewScript(`
			local current = redis.call("GET", KEYS[1])
			if not current then
				return 0
			end
			local data = cjson.decode(current)
			if data["user_id"] ~= ARGV[1] then
				return 0
			end
			redis.call("DEL", KEYS[1])
			redis.call("SREM", KEYS[2], data["seat_id"])
			return 1
		`),

		// ─── ExtendSeatIfOwner ──────────────────────────────────
		// KEYS[1] = seat hold key
		// ARGV[1] = expected owner user_id
		// ARGV[2] = additional TTL seconds
		//
		// Returns: new TTL in seconds, -1 if not owned, -2 if not present.
		ExtendSeatIfOwner: goredis.NewScript(`
			local current = redis.call("GET", KEYS[1])
			if not current then
				return -2
			end
			local data = cjson.decode(current)
			if data["user_id"] ~= ARGV[1] then
				return -1
			end
			local new_ttl = redis.call("TTL", KEYS[1]) + tonumber(ARGV[2])
			if new_ttl <= 0 then
				return -2
			end
			redis.call("EXPIRE", KEYS[1], new_ttl)
			return new_ttl
		`),

		// ─── ConfirmSeatIfOwner ─────────────────────────────────
		// KEYS[1] = seat hold key
		// KEYS[2] = sold seats set
		// KEYS[3] = held seats set
		// ARGV[1] = expected owner user_id
		//
		// Returns: 1 on success, 0 if not owned.
		ConfirmSeatIfOwner: goredis.NewScript(`
			local current = redis.call("GET", KEYS[1])
			if not current then
				return 0
			end
			local data = cjson.decode(current)
			if data["user_id"] ~= ARGV[1] then
				return 0
			end
			local seat_id = data["seat_id"]
			redis.call("DEL", KEYS[1])
			redis.call("SREM", KEYS[3], seat_id)
			redis.call("SADD", KEYS[2], seat_id)
			return 1
		`),

		// ─── HoldGaZone ─────────────────────────────────────────
		// KEYS[1] = ga held counter
		// KEYS[2] = ga sold counter
		// KEYS[3] = ga capacity
		// ARGV[1] = quantity to hold
		// ARGV[2] = TTL seconds for the held counter key
		//
		// Returns:
		//   >0  = new held count after successful hold
		//   -1  = insufficient capacity (capacity - sold - held < quantity)
		//   -2  = capacity key missing (zone not initialized)
		//
		// All reads of capacity/sold/held happen inside the script, so two
		// callers cannot both pass the capacity check against a stale view.
		HoldGaZone: goredis.NewScript(`
			local requested = tonumber(ARGV[1])
			local ttl       = tonumber(ARGV[2])

			local capacity_raw = redis.call("GET", KEYS[3])
			if not capacity_raw then
				return -2
			end
			local capacity = tonumber(capacity_raw)

			local sold = tonumber(redis.call("GET", KEYS[2]) or "0")
			local held = tonumber(redis.call("GET", KEYS[1]) or "0")

			local available = capacity - sold - held
			if available < requested then
				return -1
			end

			local new_held = redis.call("INCRBY", KEYS[1], requested)
			redis.call("EXPIRE", KEYS[1], ttl)
			return new_held
		`),

		// ─── ReleaseGaIfOwner ───────────────────────────────────
		// KEYS[1] = ga held counter
		// KEYS[2] = hold token key
		// ARGV[1] = expected user_id
		// ARGV[2] = quantity to release
		ReleaseGaIfOwner: goredis.NewScript(`
			local token = redis.call("GET", KEYS[2])
			if not token then
				return -1
			end
			local data = cjson.decode(token)
			if data["user_id"] ~= ARGV[1] then
				return -1
			end
			local qty = tonumber(ARGV[2])
			local new_held = redis.call("DECRBY", KEYS[1], qty)
			if new_held < 0 then
				redis.call("SET", KEYS[1], 0)
				new_held = 0
			end
			redis.call("DEL", KEYS[2])
			return new_held
		`),

		// ─── ConfirmGaIfOwner ───────────────────────────────────
		// KEYS[1] = ga held counter
		// KEYS[2] = ga sold counter
		// KEYS[3] = hold token key
		// ARGV[1] = expected user_id
		// ARGV[2] = quantity to move from held to sold
		ConfirmGaIfOwner: goredis.NewScript(`
			local token = redis.call("GET", KEYS[3])
			if not token then
				return 0
			end
			local data = cjson.decode(token)
			if data["user_id"] ~= ARGV[1] then
				return 0
			end
			local qty = tonumber(ARGV[2])
			redis.call("DECRBY", KEYS[1], qty)
			redis.call("INCRBY", KEYS[2], qty)
			redis.call("DEL", KEYS[3])
			return 1
		`),
	}
}

// Eval is a thin wrapper that runs a script against the client, taking
// care of the KEYS/ARGV split.
func (c *Client) Eval(ctx context.Context, script *goredis.Script, keys []string, args ...any) *goredis.Cmd {
	return script.Run(ctx, c.Client, keys, args...)
}