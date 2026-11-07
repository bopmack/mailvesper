@extends('mail.layout')
@section('body')
<h1 style="font-size:20px;margin:0 0 12px">{{ preg_replace('/^\[[^\]]{1,80}\] /', '', $title) }}</h1>
@foreach ($lines as $line)<p style="font-size:15px;line-height:1.55;color:#3F3F46;margin:0 0 10px">{{ $line }}</p>@endforeach
<a href="{{ $url }}" style="display:inline-block;margin-top:8px;background:#5B5BF7;color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:11px 18px;border-radius:10px">Open System status</a>
<p style="font-size:12px;color:#8A8A93;margin:20px 0 0">Sent to {{ \App\Services\Platform\Brand::name() }}’s platform admins at {{ now()->toDayDateTimeString() }} UTC.</p>
@endsection
