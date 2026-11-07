@extends('layouts.app')
@section('title', 'Edit customer')
@section('back', route('billing.customers'))
@section('content')
<form method="POST" action="{{ route('billing.customers.update', $customer) }}" class="card p-6 max-w-xl">@csrf @method('PUT') @include('billing.customer-fields')<button class="btn btn-primary">Save customer</button></form>
@endsection
