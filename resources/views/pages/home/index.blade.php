@extends('layouts.app')


@section('title','Jasa Pembuatan Website Purwokerto')

@section('content')
<x-pages.home.hero />
<x-pages.home.tech-stack />
<x-pages.home.hero-split />
<x-pages.home.pricing-section :services="$services" />
<x-pages.home.proccess-section />
<x-pages.home.reviews />
<x-pages.home.portfolio :portfolios="$portfolios" />
<x-pages.home.final-cta />
    
@endsection