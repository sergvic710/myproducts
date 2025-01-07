{{--@dump($product ?? '')--}}
{{--@dump($errors ?? '')--}}
<form class="max-w-md mx-auto" method="post" action="{{ $route }}" enctype="multipart/form-data">
    @csrf
    <input type="hidden" name="_method" value="{{ $method }}">
    <div class="relative z-0 w-full mb-5 group">
        <div class="mb-6 relative">
            <label class="block mb-2 text-sm font-medium text-gray-900" for="image">Image</label>
            <input class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 focus:outline-none" id="image" type="file" name="image">
        </div>

        <div class="mb-6 relative">
            <input type="text" name="name" id="name" class="block py-2.5 px-0 w-full text-sm text-gray-900 bg-transparent border-0 border-b-2 border-gray-300 appearance-none focus:outline-none focus:ring-0 focus:border-blue-600 peer" placeholder=" " required value="{{ $product->name ?? '' }}"/>
            <label for="name" class="peer-focus:font-medium absolute text-sm text-gray-500 duration-300 transform -translate-y-6 scale-75 top-3 -z-10 origin-[0] peer-focus:start-0 rtl:peer-focus:translate-x-1/4 rtl:peer-focus:left-auto peer-focus:text-blue-600 peer-focus:dark:text-blue-500 peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-6">Name</label>
        </div>

        <div class="mb-6 relative">
            <label for="category" class="block mb-2 text-sm font-medium text-gray-900">Select category </label>
            <select id="category" name="category_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
                @foreach($categories as $item)
                    @if( isset($product) )
                        <option value="{{ $item->id }}" {{ ($product->category_id == $item->id) ? 'selected' : '' }}> {{ $item->name  }}</option>
                    @else
                        <option value="{{ $item->id }}"> {{ $item->name  }}</option>
                    @endif
                @endforeach
            </select>
        </div>

        <div class="mb-6 relative">
            <label for="unit" class="block mb-2 text-sm font-medium text-gray-900">Select unit </label>
            <select id="unit" name="unit_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
                @foreach($units as $item)
                    @if( isset($product)  )
                        <option value="{{ $item->id }}" {{ ($product->unit_id == $item->id) ? 'selected' : '' }}> {{ $item->name  }}</option>
                    @else
                        <option value="{{ $item->id }}"> {{ $item->name  }}</option>
                    @endif
                @endforeach
            </select>
        </div>

        <div class="mb-6 relative">
            <input type="text" name="count" id="count" class="block py-2.5 px-0 w-full text-sm text-gray-900 bg-transparent border-0 border-b-2 border-gray-300 appearance-none focus:outline-none focus:ring-0 focus:border-blue-600 peer" placeholder=" " required value="{{ $product->count ?? '' }}"/>
            <label for="count" class="absolute text-sm text-gray-500 duration-300 transform -translate-y-6 scale-75 top-3 -z-10 origin-[0] peer-focus:start-0 peer-focus:text-blue-600 peer-focus:dark:text-blue-500 peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-6 rtl:peer-focus:translate-x-1/4 rtl:peer-focus:left-auto">For</label>
        </div>

        <button type="submit" class="mt-3 text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 focus:outline-none">Save</button>
    </div>
</form>
@if ($errors->any())
    <div class="alert alert-danger">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
