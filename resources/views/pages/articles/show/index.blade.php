@extends('layouts.app')

@section('title', $article->title)
@section('description', $article->excerpt)

@section('content')
    <x-pages.articles.show-header :article="$article" />
    <x-pages.articles.show-content :article="$article" />
    <x-pages.articles.related-articles :articles="$relatedArticles" />
@endsection