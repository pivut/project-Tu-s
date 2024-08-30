<!-- resources/views/categories/show.blade.php -->
@extends('layouts.app')

@section('title', 'Category Details')

@section('content')
    <h1>Category Details</h1>

    <p><strong>Name:</strong> {{ $category->name }}</p>

    <a href="{{ route('categories.index') }}" class="btn btn-secondary">Back</a>
    <a href="{{ route('categories.edit', $category->id) }}" class="btn btn-warning">Edit Category</a>
@endsection
