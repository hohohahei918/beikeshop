@php
    $lang = app()->getLocale();
@endphp
<div class="card card-primary card-outline card-tabs">
  <div class="card-header p-0 pt-1 border-bottom-0">
    <ul class="nav nav-tabs" id="mcp-tab" role="tablist">
      <li class="nav-item">
        <a class="nav-link active" id="mcp-overview-tab" data-bs-toggle="pill" href="#mcp-overview" role="tab" aria-controls="mcp-overview" aria-selected="true">{{ __('Mcp::common.nav_overview') }}</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" id="mcp-tools-tab" data-bs-toggle="pill" href="#mcp-tools" role="tab" aria-controls="mcp-tools" aria-selected="false">{{ __('Mcp::common.nav_tools') }}</a>
      </li>
      <li class="nav-item">
        <a class="nav-link" id="mcp-guide-tab" data-bs-toggle="pill" href="#mcp-guide" role="tab" aria-controls="mcp-guide" aria-selected="false">{{ __('Mcp::common.nav_guide') }}</a>
      </li>
    </ul>
  </div>
  <div class="card-body">
    <div class="tab-content" id="mcp-tab-content">

      {{-- 概要 --}}
      <div class="tab-pane fade active show" id="mcp-overview" role="tabpanel" aria-labelledby="mcp-overview-tab">
        @include('Mcp::admin.overview')
      </div>

      {{-- 工具清单 --}}
      <div class="tab-pane fade" id="mcp-tools" role="tabpanel" aria-labelledby="mcp-tools-tab">
        @include('Mcp::admin.tools')
      </div>

      {{-- 连接指引 --}}
      <div class="tab-pane fade" id="mcp-guide" role="tabpanel" aria-labelledby="mcp-guide-tab">
        @include('Mcp::admin.guide')
      </div>
    </div>
  </div>
</div>

@push('footer')
<script>
  $(function () {
    // 测试连接
    $('#mcp-test-btn').off('click').on('click', function () {
      var $btn = $(this);
      $btn.prop('disabled', true).text('...');
      $http.get('{{ admin_route('plugin.mcp.test') }}', null, {hload: true})
        .then(function (res) {
          let data = JSON.parse(res);
          $('#mcp-test-result').removeClass('d-none').text(data.message || data.msg || '');
          if (data.success) {
            $('#mcp-test-result').removeClass('text-danger').addClass('text-success');
          } else {
            $('#mcp-test-result').removeClass('text-success').addClass('text-danger');
          }
        })
        .finally(function () {
          $btn.prop('disabled', false).text('{{ __('Mcp::common.test_connection') }}');
        });
    });

    // 复制令牌
    $('#mcp-copy-token').off('click').on('click', function () {
      var $input = $('#mcp-token-input');
      $input.removeAttr('readonly').select();
      document.execCommand('copy');
      $input.attr('readonly', true);
      window.getSelection().removeAllRanges();
      var tip = $(this).closest('.input-group').find('.input-group-text');
      if (tip.length) { tip.text('{{ __('Mcp::common.copied') }}'); setTimeout(function(){ tip.text(''); }, 1500); }
    });

    // 重新生成令牌二次确认
    $('#mcp-regenerate-form').off('submit').on('submit', function (e) {
      if (!window.confirm('{{ __('Mcp::common.token_regenerate_confirm') }}')) {
        e.preventDefault();
      }
    });
  });
</script>
@endpush
