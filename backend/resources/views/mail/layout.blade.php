@php($brand = \App\Services\Platform\Brand::public())
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>{{ $brand['name'] }}</title></head>
<body style="margin:0;background:#F4F4F5;font-family:Helvetica,Arial,sans-serif;color:#18181B">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F5"><tr><td align="center" style="padding:32px 12px">
<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="width:480px;max-width:100%;background:#FFFFFF;border-radius:14px;border:1px solid #E4E4E7">
<tr><td style="padding:28px 32px 8px;font-size:18px;font-weight:700;letter-spacing:-.02em">@if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" height="32" style="height:32px;width:auto;display:block;border:0">@else{{ $brand['name'] }}@endif</td></tr>
<tr><td style="padding:8px 32px 28px">@yield('body')</td></tr>
</table>
<p style="font-size:12px;color:#8A8A93;margin:16px 0 0;max-width:480px;line-height:1.5">You received this because someone used this address on {{ $brand['name'] }}. If it wasn't you, you can ignore this email.@if($brand['company'] || $brand['address'])<br>{{ $brand['company'] }}@if($brand['company'] && $brand['address']) · @endif{{ $brand['address'] }}@endif</p>
</td></tr></table></body></html>
