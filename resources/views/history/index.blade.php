@extends('layouts.main')
@section('content')
    <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
        <div class="flex items-center justify-between flex-column md:flex-row flex-wrap space-y-4 md:space-y-0 py-4 bg-white">
            <div>
                <a href="{{ route('history.create') }}" class="text-blue-700 hover:text-white border border-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center me-2 mb-2">Add</a>
                <button id="dropdownActionButton" data-dropdown-toggle="dropdownAction" class="inline-flex items-center text-gray-500 bg-white border border-gray-300 focus:outline-none hover:bg-gray-100 focus:ring-4 focus:ring-gray-100 font-medium rounded-lg text-sm px-3 py-1.5" type="button">
                    <span class="sr-only">Action button</span>
                    Action
                    <svg class="w-2.5 h-2.5 ms-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
                    </svg>
                </button>
                <!-- Dropdown menu -->
                <div id="dropdownAction" class="z-10 hidden bg-white divide-y divide-gray-100 rounded-lg shadow w-44">
                    <ul class="py-1 text-sm text-gray-700" aria-labelledby="dropdownActionButton">
                        <li>
                            <a href="{{ route('history.import') }}" class="block px-4 py-2 hover:bg-gray-100">Import</a>
                        </li>
                        {{--                        <li>--}}
                        {{--                            <a href="#" class="block px-4 py-2 hover:bg-gray-100">Promote</a>--}}
                        {{--                        </li>--}}
                        {{--                        <li>--}}
                        {{--                            <a href="#" class="block px-4 py-2 hover:bg-gray-100">Activate account</a>--}}
                        {{--                        </li>--}}
                    </ul>
                    <div class="py-1">
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Delete User</a>
                    </div>
                </div>
            </div>

            {{--            <label for="table-search" class="sr-only">Search</label>--}}
            {{--            <div class="relative">--}}
            {{--                <div class="absolute inset-y-0 rtl:inset-r-0 start-0 flex items-center ps-3 pointer-events-none">--}}
            {{--                    <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 20 20">--}}
            {{--                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 19-4-4m0-7A7 7 0 1 1 1 8a7 7 0 0 1 14 0Z"/>--}}
            {{--                    </svg>--}}
            {{--                </div>--}}
            {{--                <input type="text" id="table-search-users" class="block pt-2 ps-10 text-sm text-gray-900 border border-gray-300 rounded-lg w-80 bg-gray-50 focus:ring-blue-500 focus:border-blue-500" placeholder="Search for users">--}}
            {{--            </div>--}}
        </div>
        <form class="max-w-md mx-auto" method="post" action="{{ route('history.search') }}">
            @csrf
            <div class="relative z-0 w-full mb-5 group">
                <div class="mb-6 relative">
                    <label for="product_id" class="block mb-2 text-sm font-medium text-gray-900">Search by product </label>
                    <select id="product_id" name="product_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
                        @foreach($products as $item)
                            @if( isset($product_id)  )
                                <option value="{{ $item->id }}" {{ ($product_id == $item->id) ? 'selected' : '' }}> {{ $item->name }}</option>
                            @else
                                <option value="{{ $item->id }}"> {{ $item->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button type="submit" class="mt-3 text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 focus:outline-none">Search</button>
                </div>
            </div>
        </form>

        <table class="w-full text-sm text-left rtl:text-right text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
            <tr>
                {{--                <th scope="col" class="p-4">--}}
                {{--                    <div class="flex items-center">--}}
                {{--                        <input id="checkbox-all-search" type="checkbox" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2">--}}
                {{--                        <label for="checkbox-all-search" class="sr-only">checkbox</label>--}}
                {{--                    </div>--}}
                {{--                </th>--}}
                <th scope="col" class="px-6 py-3">
                    Date
                </th>
                <th scope="col" class="px-6 py-3">
                    Shop
                </th>
                <th scope="col" class="px-6 py-3">
                    Product
                </th>
                <th scope="col" class="px-6 py-3">
                    Amount
                </th>
                <th scope="col" class="px-6 py-3">
                    Total
                </th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($histories as $item)
                <tr class="bg-white border-b hover:bg-gray-50">
                    {{--                <td class="w-4 p-4">--}}
                    {{--                    <div class="flex items-center">--}}
                    {{--                        <input id="checkbox-table-search-1" type="checkbox" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2">--}}
                    {{--                        <label for="checkbox-table-search-1" class="sr-only">checkbox</label>--}}
                    {{--                    </div>--}}
                    {{--                </td>--}}
                    <td class="px-6 py-1">
                        {{ \Carbon\Carbon::parse($item->date)->format('d.m.Y') }}
                    </td>
                    </td>
                    <td class="px-6 py-1">
                        {{ $item->shop->name }}
                    </td>
                    <th scope="row" class="flex items-center px-6 py-4 whitespace-nowrap">
                        @if( $item->product->getMedia('image')->count() != 0 )
                            <img class="w-10 h-10 rounded-full" src="{{$item->product->getMedia('image')[0]->getUrl()}}" alt="">
                        @endif
                        <div class="ps-3">
                            <div class="text-base">{{ $item->product->name }}</div>
                            <div class="font-normal text-gray-500"></div>
                        </div>
                    </th>
{{--                    <td class="px-6 py-1">--}}
{{--                        {{ $item->product->name }}--}}
{{--                    </td>--}}
                    <td class="px-6 py-1">
                        {{ $item->amount}}  {{ $item->product->unit->name }}
                    </td>
                    <td class="px-6 py-1">
                        {{ $item->total}} €
                    </td>
                    <td class="px-6 py-1">
                        <div class="inline-flex rounded-md shadow-sm">
                            <!-- Modal toggle -->
                            {{--                        <a href="#" type="button" data-modal-target="editUserModal" data-modal-show="editUserModal" class="font-medium text-blue-600 hover:underline">Edit</a>--}}
                            <a href="{{ route('history.edit', $item->id) }}" class="text-blue-700 hover:text-white border border-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center me-2 mb-2">Edit</a>
                            <form method="POST" action="{{ route('history.destroy', $item->id) }}">
                                {{ csrf_field() }}
                                {{ method_field('DELETE') }}
                                <button type="submit" class="text-red-700 hover:text-white border border-red-700 hover:bg-red-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center me-2 mb-2">Remove</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="my-2 mx-auto w-full flex justify-center relative items-center">
            {{ $histories->links() }}
        </div>

        <!-- Edit user modal -->
        <div id="editUserModal" tabindex="-1" aria-hidden="true" class="fixed top-0 left-0 right-0 z-50 items-center justify-center hidden w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
            <div class="relative w-full max-w-2xl max-h-full">
                <!-- Modal content -->
                <form class="relative bg-white rounded-lg shadow">
                    <!-- Modal header -->
                    <div class="flex items-start justify-between p-4 border-b rounded-t">
                        <h3 class="text-xl font-semibold text-gray-900">
                            Edit user
                        </h3>
                        <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center" data-modal-hide="editUserModal">
                            <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                            </svg>
                            <span class="sr-only">Close modal</span>
                        </button>
                    </div>
                    <!-- Modal body -->
                    <div class="p-6 space-y-6">
                        {{--                        @include('category._form')--}}
                        {{--                        <div class="grid grid-cols-6 gap-6">--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="first-name" class="block mb-2 text-sm font-medium text-gray-900">First Name</label>--}}
                        {{--                                <input type="text" name="first-name" id="first-name" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="Bonnie" required="">--}}
                        {{--                            </div>--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="last-name" class="block mb-2 text-sm font-medium text-gray-900">Last Name</label>--}}
                        {{--                                <input type="text" name="last-name" id="last-name" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="Green" required="">--}}
                        {{--                            </div>--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="email" class="block mb-2 text-sm font-medium text-gray-900">Email</label>--}}
                        {{--                                <input type="email" name="email" id="email" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="example@company.com" required="">--}}
                        {{--                            </div>--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="phone-number" class="block mb-2 text-sm font-medium text-gray-900">Phone Number</label>--}}
                        {{--                                <input type="number" name="phone-number" id="phone-number" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="e.g. +(12)3456 789" required="">--}}
                        {{--                            </div>--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="department" class="block mb-2 text-sm font-medium text-gray-900">Department</label>--}}
                        {{--                                <input type="text" name="department" id="department" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="Development" required="">--}}
                        {{--                            </div>--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="company" class="block mb-2 text-sm font-medium text-gray-900">Company</label>--}}
                        {{--                                <input type="number" name="company" id="company" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="123456" required="">--}}
                        {{--                            </div>--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="current-password" class="block mb-2 text-sm font-medium text-gray-900">Current Password</label>--}}
                        {{--                                <input type="password" name="current-password" id="current-password" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="••••••••" required="">--}}
                        {{--                            </div>--}}
                        {{--                            <div class="col-span-6 sm:col-span-3">--}}
                        {{--                                <label for="new-password" class="block mb-2 text-sm font-medium text-gray-900">New Password</label>--}}
                        {{--                                <input type="password" name="new-password" id="new-password" class="shadow-sm bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-600 focus:border-blue-600 block w-full p-2.5" placeholder="••••••••" required="">--}}
                        {{--                            </div>--}}
                        {{--                        </div>--}}
                    </div>
                    <!-- Modal footer -->
                    <div class="flex items-center p-6 space-x-3 rtl:space-x-reverse border-t border-gray-200 rounded-b">
                        <button type="submit" class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center">Save all</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
