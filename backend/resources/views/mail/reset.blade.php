@extends('mail.layout')
@section('body')
<h1 style="font-size:22px;margin:0 0 8px">Reset your password</h1>
<p style="font-size:15px;line-height:1.55;color:#52525B;margin:0 0 20px">Click the button to choose a new password. The link works for 30 minutes and can be used once.</p>
<a href="{{ $url }}" style="display:inline-block;background:#5B5BF7;color:#FFFFFF;text-decoration:none;font-weight:700;font-size:15px;padding:12px 22px;border-radius:10px">Choose a new password</a>
<p style="font-size:13px;color:#8A8A93;margin:20px 0 0">Didn't ask for this? Your password hasn't changed.</p>
@endsection
