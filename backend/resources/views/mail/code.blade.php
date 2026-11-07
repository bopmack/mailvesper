@extends('mail.layout')
@section('body')
<h1 style="font-size:22px;margin:0 0 8px">{{ $heading }}</h1>
<p style="font-size:15px;line-height:1.55;color:#52525B;margin:0 0 20px">{{ $intro }}</p>
<div style="font-family:'Courier New',monospace;font-size:34px;font-weight:700;letter-spacing:.3em;background:#F4F4F5;border-radius:10px;padding:16px 0;text-align:center">{{ $code }}</div>
<p style="font-size:13px;color:#8A8A93;margin:16px 0 0">The code expires in 10 minutes. Never share it with anyone.</p>
@endsection
