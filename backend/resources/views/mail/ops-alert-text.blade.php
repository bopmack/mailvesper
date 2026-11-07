{{ preg_replace('/^\[[^\]]{1,80}\] /', '', $title) }}

@foreach ($lines as $line)
{{ $line }}
@endforeach

System status: {{ $url }}
