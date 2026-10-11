@extends('layouts.app')

@section('title',$portfolio->title)


@section('content')
 <x-pages.portfolio.detail :portfolio="$portfolio"
    :screenshots="$screenshots" />


@endsection