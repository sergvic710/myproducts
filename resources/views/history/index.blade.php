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
                    </ul>
                    <div class="py-1">
                        <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Delete User</a>
                    </div>
                </div>
            </div>

        </div>
        <form class="w-full mx-auto" method="post" action="{{ route('history.search') }}">
            @csrf
            <div class="relative z-0 w-full mb-5 flex items-end">
                <div class="mb-6 relative  pl-0 pr-4">
                    <div class="absolute inset-y-0 start-0 flex items-center ps-3.5 pointer-events-none">
                        <svg class="w-4 h-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M20 4a2 2 0 0 0-2-2h-2V1a1 1 0 0 0-2 0v1h-3V1a1 1 0 0 0-2 0v1H6V1a1 1 0 0 0-2 0v1H2a2 2 0 0 0-2 2v2h20V4ZM0 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8H0v10Zm5-8h10a1 1 0 0 1 0 2H5a1 1 0 0 1 0-2Z"/>
                        </svg>
                    </div>
                    <input datepicker datepicker-autohide id="date" name="date" type="text" value="{{ $date }}" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full ps-10 p-2.5" placeholder="Select date">
                </div>
                <div class="mb-6 relative  px-4">
                    <label for="product_id" class="block mb-2 text-sm font-medium text-gray-900">Search by product </label>
                    <select id="product_id" name="product_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
                        <option value="-1"> Select product</option>
                        @foreach($products as $item)
                            @if( isset($product_id)  )
                                <option value="{{ $item->id }}" {{ ($product_id == $item->id) ? 'selected' : '' }}> {{ $item->name }}</option>
                            @else
                                <option value="{{ $item->id }}"> {{ $item->name }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="mb-6 relative px-4">
                    <button type="submit" class="mt-3 text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 focus:outline-none">Search</button>
                    <a href="{{ route('history.index') }}" class="mt-3 text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 focus:outline-none">Reset</a>
                </div>
            </div>
            <div class="relative z-0 w-full mb-5 flex">
            </div>
        </form>

        <table class="w-full text-sm text-left rtl:text-right text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
            <tr>
                <!-- Date Column -->
                <th scope="col" class="px-6 py-3">
                    <div class="flex items-center">
                        @php
                            $newSortDirection = ($sortField === 'date' && $sortDirection === 'asc') ? 'desc' : 'asc';
                            $sortIcon = $sortField === 'date' ? ($sortDirection === 'asc' ? '↑' : '↓') : '↕';
                        @endphp
                        <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'date', 'sort_dir' => $newSortDirection]) }}" class="flex items-center">
                            Date
                            <span class="ml-1">{{ $sortIcon }}</span>
                        </a>
                    </div>
                </th>

                <!-- Shop Column -->
                <th scope="col" class="px-6 py-3">
                    Shop
                </th>

                <!-- Product Column -->
                <th scope="col" class="px-6 py-3">
                    <div class="flex items-center">
                        @php
                            $newSortDirection = ($sortField === 'product_name' && $sortDirection === 'asc') ? 'desc' : 'asc';
                            $sortIcon = $sortField === 'product_name' ? ($sortDirection === 'asc' ? '↑' : '↓') : '↕';
                        @endphp
                        <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'product_name', 'sort_dir' => $newSortDirection]) }}" class="flex items-center">
                            Product
                            <span class="ml-1">{{ $sortIcon }}</span>
                        </a>
                    </div>
                </th>

                <!-- Amount Column -->
                <th scope="col" class="px-6 py-3">
                    Price
                </th>
                <!-- Amount Column -->
                <th scope="col" class="px-6 py-3">
                    Amount
                </th>

                <!-- Total Column -->
                <th scope="col" class="px-6 py-3">
                    Total
                </th>
            </tr>
            </thead>
            <tbody>
            @foreach($histories as $item)
                <tr class="bg-white border-b hover:bg-gray-50">
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
                    <td class="px-6 py-1">
                        {{ $item->price}} €
                    </td>
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
