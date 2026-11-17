@foreach ($posts as $post)
  <h2>{{ $post->title }}</h2>
  <p>{{ $post->published_at->format('d M Y') }} · {{ $post->status->label() }}</p>
  <p>by {{ $post->author->name }} · {{ $post->tags->count() }} tags · {{ $post->comments->count() }} comments</p>
  @foreach ($post->comments as $comment)
    <li>{{ $comment->user->name }}: {{ $comment->body }}</li>
  @endforeach
@endforeach
