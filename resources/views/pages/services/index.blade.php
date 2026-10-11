@extends('layouts.app')

@section('title','Layanan - Velora Digital')

@section('content')

<x-pages.services.hero  />
<x-pages.services.grid :services="$services" />


@endsection