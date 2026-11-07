@extends('mail.layout')
@section('body')
<h1 style="font-size:22px;margin:0 0 8px">Your system email works</h1>
<p style="font-size:15px;line-height:1.55;color:#52525B;margin:0">This test came from {{ \App\Services\Platform\Brand::name() }}’s admin panel. Sign-in codes, invitations, password resets and alerts go out the same way.</p>
@endsection
