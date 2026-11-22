@foreach ($posts as $post)
    @include('social.partials.post', ['post' => $post])
@endforeach
