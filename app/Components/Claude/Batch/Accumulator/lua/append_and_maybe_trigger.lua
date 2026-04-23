-- KEYS[1] = bucket list key (acc:{c}:{m}:{w})
-- KEYS[2] = bucket meta hash key (acc:{c}:{m}:{w}:meta)
-- KEYS[3] = bucket custom_ids set key (acc:{c}:{m}:{w}:ids)
-- KEYS[4] = flush-pending set key (acc:pending)
-- ARGV[1] = item JSON payload
-- ARGV[2] = custom_id
-- ARGV[3] = item size bytes
-- ARGV[4] = now (unix seconds)
-- ARGV[5] = trigger_count
-- ARGV[6] = trigger_bytes
-- ARGV[7] = trigger_seconds
-- Returns: {status, position, should_flush}

if redis.call('SISMEMBER', KEYS[3], ARGV[2]) == 1 then
  return {-1, 0, 0}
end

redis.call('SADD', KEYS[3], ARGV[2])
redis.call('RPUSH', KEYS[1], ARGV[1])
local position = redis.call('LLEN', KEYS[1])

local first = redis.call('HGET', KEYS[2], 'first_append_at')
if not first then
  redis.call('HSET', KEYS[2], 'first_append_at', ARGV[4])
  first = ARGV[4]
end
redis.call('HINCRBY', KEYS[2], 'total_bytes', tonumber(ARGV[3]))
redis.call('EXPIRE', KEYS[1], 600)
redis.call('EXPIRE', KEYS[2], 600)
redis.call('EXPIRE', KEYS[3], 600)

local total_bytes = tonumber(redis.call('HGET', KEYS[2], 'total_bytes'))
local age = tonumber(ARGV[4]) - tonumber(first)

local should_flush = 0
if position >= tonumber(ARGV[5]) then should_flush = 1 end
if total_bytes >= tonumber(ARGV[6]) then should_flush = 1 end
if age >= tonumber(ARGV[7]) then should_flush = 1 end

if should_flush == 1 then
  redis.call('SADD', KEYS[4], KEYS[1])
end

return {1, position, should_flush}
