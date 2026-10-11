@extends('layouts.app')

@section('title', 'Artikel')

@section('content')
    <div class="bg-gradient-to-r from-[#20B7FF] via-[#696FC7] to-[#7C3AED]">
        <x-pages.articles.hero />
        <x-pages.articles.article-grid :articles="$articles" />

    </div>


@endsection
