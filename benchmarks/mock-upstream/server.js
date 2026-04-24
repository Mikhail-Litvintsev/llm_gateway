const http = require('http');

const delayMs = parseInt(process.env.UPSTREAM_DELAY_MS || '0', 10);
const port = parseInt(process.env.UPSTREAM_PORT || '8090', 10);

const successBody = JSON.stringify({
  id: 'msg_mock_1',
  type: 'message',
  role: 'assistant',
  model: 'claude-sonnet-4-6',
  content: [{ type: 'text', text: 'Mocked upstream response.' }],
  stop_reason: 'end_turn',
  stop_sequence: null,
  usage: {
    input_tokens: 10,
    output_tokens: 12,
    cache_read_input_tokens: 0,
    cache_creation_input_tokens: 0,
  },
});

function handler(req, res) {
  let received = '';
  req.on('data', (chunk) => {
    received += chunk;
  });
  req.on('end', () => {
    const reply = () => {
      res.writeHead(200, {
        'Content-Type': 'application/json',
        'anthropic-request-id': 'mock-' + Date.now(),
        'anthropic-ratelimit-requests-remaining': '1000',
        'anthropic-ratelimit-requests-reset': new Date(Date.now() + 60_000).toISOString(),
      });
      res.end(successBody);
    };
    if (delayMs > 0) {
      setTimeout(reply, delayMs);
    } else {
      reply();
    }
  });
}

if (require.main === module) {
  http.createServer(handler).listen(port, () => {
    console.log(`mock-upstream listening on :${port} (delay=${delayMs}ms)`);
  });
}

module.exports = { handler };
