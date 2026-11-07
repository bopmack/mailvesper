{{ $workspace }}: before your sending is limited

@foreach ($alerts as $a)
{{ $a['urgent'] ? '[Action needed] ' : '' }}{{ $a['title'] }}
{{ $a['detail'] }}

@endforeach
Open Sending: {{ $url }}
