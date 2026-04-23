-- KEYS[1] = bucket list key (acc:{c}:{m}:{w})
-- KEYS[2] = bucket meta hash key (acc:{c}:{m}:{w}:meta)
-- KEYS[3] = bucket custom_ids set key (acc:{c}:{m}:{w}:ids)
-- KEYS[4] = flush-pending set key (acc:pending)
-- Returns: {items_json_array..., "---META---", field1, value1, field2, value2, ...}

local items = redis.call('LRANGE', KEYS[1], 0, -1)
local meta = redis.call('HGETALL', KEYS[2])

redis.call('DEL', KEYS[1], KEYS[2], KEYS[3])
redis.call('SREM', KEYS[4], KEYS[1])

local result = {}
for i, v in ipairs(items) do
  result[#result + 1] = v
end
result[#result + 1] = '---META---'
for i, v in ipairs(meta) do
  result[#result + 1] = v
end

return result
