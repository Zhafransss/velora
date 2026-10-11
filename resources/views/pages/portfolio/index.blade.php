@extends('layouts.app')

@section('title','Portfolio')

@section('content')
 <x-pages.portfolio :portfolios="$portfolios" :categories="$categories" />


@endsection