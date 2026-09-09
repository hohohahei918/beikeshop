@php $lang = app()->getLocale(); @endphp

<h6 class="text-muted">{{ __('Mcp::common.tools_title') }}</h6>
<p class="small text-muted">{{ __('Mcp::common.tools_intro') }}</p>

<div class="table-responsive">
  <table class="table table-sm table-bordered align-middle">
    <thead class="table-light">
      <tr>
        <th style="width: 40%;">Tool Name</th>
        <th>Description</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($tools as $tool)
        <tr>
          <td class="font-monospace">{{ $tool['name'] }}</td>
          <td class="small">{{ $tool['description'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
