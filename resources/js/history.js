// import { Datepicker } from 'flowbite';
document.addEventListener('DOMContentLoaded', function () {
    const total = document.getElementById('total');
    const price = document.getElementById('price');
    const amount = document.getElementById('amount');
    total.value = price.value * amount.value;

    price.addEventListener('change', function (e) {
        let value = price.value * amount.value
        total.value = value.toFixed(2)
    })
    amount.addEventListener('change', function (e) {
        let value = price.value * amount.value
        total.value = value.toFixed(2)
    })

    // // set the target element of the input field
    // const $datepickerEl = document.getElementById('date');
    // const options = {
    //     format: 'dd.mm.yyyy',
    // }
    //
    // const datepicker = new Datepicker($datepickerEl, options);

});
