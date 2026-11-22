@foreach ($comments as $comment)
    @include('social.partials.comment', ['comment' => $comment])
@endforeach
