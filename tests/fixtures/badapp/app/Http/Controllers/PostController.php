<?php
namespace App\Http\Controllers;
use App\Models\Post;
use Illuminate\Http\Request;
class PostController
{
    public function index() { return view('posts.index', ['posts' => Post::with('tags')->get()]); }
    public function store(Request $request) { return Post::create($request->all()); }
}
