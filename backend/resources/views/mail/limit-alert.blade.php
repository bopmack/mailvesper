@extends('mail.layout')
@section('body')
<h1 style="font-size:20px;margin:0 0 12px">{{ $workspace }}: before your sending is limited</h1>
@foreach ($alerts as $a)
<p style="font-size:15px;line-height:1.55;color:#18181B;margin:0 0 4px;font-weight:700">{{ $a['urgent'] ? '⚠ ' : '' }}{{ $a['title'] }}</p>
<p style="font-size:15px;line-height:1.55;color:#3F3F46;margin:0 0 14px">{{ $a['detail'] }}</p>
@endforeach
<a href="{{ $url }}" style="display:inline-block;margin-top:4px;background:#5B5BF7;color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14px;padding:11px 18px;border-radius:10px">Open Sending</a>
<p style="font-size:12px;color:#8A8A93;margin:20px 0 0">Sent to the owners and admins of {{ $workspace }}. We remind you once a day while it lasts.</p>
@endsection
