@php $lang = app()->getLocale(); @endphp

<h6 class="text-muted">{{ __('Mcp::common.guide_title') }}</h6>
<p class="small text-muted">{{ __('Mcp::common.guide_intro') }}</p>

<div class="row">
  <div class="col-md-6">
    <div class="card border">
      <div class="card-header py-2">{{ __('Mcp::common.guide_client') }}</div>
      <div class="card-body p-0">
        <pre class="m-0 p-3 small"><code># macOS / Linux
MCP_SERVERS='
{
  "beikeshop": {
    "type": "http",
    "url": "{{ $endpoint }}",
    "headers": { "Authorization": "Bearer {{ $token }}" }
  }
}'

# 旧版 stdio 客户端接入示意（若仅支持 stdio）：
# 可将本服务作为流桥接，指向 Python stdio 客户端。
</code></pre>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border">
      <div class="card-header py-2">{{ __('Mcp::common.guide_curl') }}</div>
      <div class="card-body p-0">
        <pre class="m-0 p-3 small"><code>curl -X POST {{ $endpoint }} \
  -H "Authorization: Bearer {{ $token }}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"{{ $health['protocol_version'] }}","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
</code></pre>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-secondary mt-3 mb-0 small">
  {{ __('Mcp::common.guide_note') }}
</div>
