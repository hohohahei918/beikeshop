@php $lang = app()->getLocale(); @endphp

<div class="row">
  <div class="col-md-6">
    <h6 class="text-muted">{{ __('Mcp::common.nav_overview') }}</h6>
    <table class="table table-sm table-bordered align-middle">
      <tbody>
        <tr>
          <th class="w-40">{{ __('Mcp::common.status') }}</th>
          <td>
            <span class="badge bg-success">{{ __('Mcp::common.status_running') }}</span>
          </td>
        </tr>
        <tr>
          <th>{{ __('Mcp::common.field_endpoint') }}</th>
          <td>
            <div class="input-group input-group-sm">
              <input type="text" class="form-control" value="{{ $endpoint }}" readonly>
              <span class="input-group-text"><a href="{{ $endpoint }}" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a></span>
            </div>
          </td>
        </tr>
        <tr>
          <th>{{ __('Mcp::common.server_version') }}</th>
          <td>{{ $health['server_name'] }} v{{ $health['server_version'] }} ({{ $health['protocol_version'] }})</td>
        </tr>
        <tr>
          <th>{{ __('Mcp::common.product_count') }}</th>
          <td>{{ $health['product_count'] }}</td>
        </tr>
        <tr>
          <th>{{ __('Mcp::common.database') }}</th>
          <td><span class="badge bg-{{ $health['database'] === 'ok' ? 'success' : 'danger' }}">{{ $health['database'] }}</span></td>
        </tr>
        <tr>
          <th>{{ __('Mcp::common.tools_available') }}</th>
          <td>{{ $health['tools_available'] }}</td>
        </tr>
        <tr>
          <th>{{ __('Mcp::common.php_version') }}</th>
          <td>{{ $health['php_version'] }}</td>
        </tr>
        <tr>
          <th>{{ __('Mcp::common.accessed_at') }}</th>
          <td>{{ $health['time'] }}</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="col-md-6">
    <h6 class="text-muted">{{ __('Mcp::common.nav_settings') }}</h6>

    {{-- 配置保存（走官方插件保存路由） --}}
    <form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST">
      @csrf
      {{ method_field('put') }}

      <div class="mb-3">
        <label class="form-label">{{ __('Mcp::common.field_token') }}</label>
        <div class="input-group">
          <input type="text" id="mcp-token-input" name="token" class="form-control font-monospace" value="{{ $token }}" readonly>
          <button type="button" id="mcp-copy-token" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clipboard"></i></button>
        </div>
        <div class="form-text">{{ __('Mcp::common.field_token_tip') }}</div>
      </div>

      <div class="mb-3">
        <label class="form-label">{{ __('Mcp::common.field_baidu_appid') }}</label>
        <input type="text" name="baidu_appid" class="form-control" value="{{ $baiduAppid }}">
      </div>
      <div class="mb-3">
        <label class="form-label">{{ __('Mcp::common.field_baidu_secret') }}</label>
        <input type="text" name="baidu_secret" class="form-control" value="{{ $baiduSecret }}">
        <div class="form-text">{{ __('Mcp::common.field_baidu_tip') }}</div>
      </div>

      <button type="submit" class="btn btn-primary btn-sm">{{ __('Mcp::common.save_settings') }}</button>
      <button type="button" id="mcp-test-btn" class="btn btn-outline-info btn-sm">{{ __('Mcp::common.test_connection') }}</button>
      <span id="mcp-test-result" class="ms-2 d-none font-monospace small"></span>
    </form>

    {{-- 重新生成令牌（独立表单，避免误保存其它字段） --}}
    <hr>
    <form id="mcp-regenerate-form" action="{{ admin_route('plugin.mcp.regenerate_token') }}" method="POST">
      @csrf
      <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-arrow-clockwise"></i> {{ __('Mcp::common.regenerate_token') }}</button>
    </form>
  </div>
</div>
