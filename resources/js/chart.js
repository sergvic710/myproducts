// import NiceSelect from "nice-select2";

// NiceSelect.bind(document.getElementById('product_id'))

const ctx = document.getElementById('priceChart').getContext('2d');
const chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: dates,
        datasets: [{
            label: 'Price',
            data: priceValues,
            backgroundColor: 'rgba(255, 99, 132, 0.2)',
            borderColor: 'rgba(255, 99, 132, 1)',
            borderWidth: 1
        }]
    },
    options: {
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
