import Swal from 'sweetalert2';

// Base customized SweetAlert instance with Light Slate theme
const customSwal = Swal.mixin({
    background: '#ffffff', // pure white
    color: '#0f172a',      // slate-900
    confirmButtonColor: '#4f46e5', // indigo-600
    cancelButtonColor: '#94a3b8',  // slate-400
    customClass: {
        popup: 'border border-slate-200 rounded-2xl shadow-xl backdrop-blur-md',
        title: 'text-slate-900 font-bold text-lg',
        htmlContainer: 'text-slate-600 text-xs leading-relaxed',
        confirmButton: 'px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-md shadow-indigo-600/20 transition-all cursor-pointer',
        cancelButton: 'px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium rounded-lg border border-slate-300 transition-all cursor-pointer mr-2',
        denyButton: 'px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-lg shadow-md shadow-rose-600/20 transition-all cursor-pointer',
    },
    buttonsStyling: true,
});

// Toast mixin for quick non-blocking status updates
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
    background: '#ffffff',
    color: '#0f172a',
    customClass: {
        popup: 'border border-slate-200 rounded-xl shadow-lg',
        title: 'text-xs font-medium text-slate-800',
    },
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    }
});

export const notify = {
    /**
     * Show a success modal alert
     */
    success(title, text = '') {
        return customSwal.fire({
            icon: 'success',
            title,
            text,
            iconColor: '#10b981', // emerald-500
        });
    },

    /**
     * Show an error modal alert
     */
    error(title, text = '') {
        return customSwal.fire({
            icon: 'error',
            title,
            text,
            iconColor: '#f43f5e', // rose-500
        });
    },

    /**
     * Show a warning modal alert
     */
    warning(title, text = '') {
        return customSwal.fire({
            icon: 'warning',
            title,
            text,
            iconColor: '#f59e0b', // amber-500
        });
    },

    /**
     * Show an info modal alert
     */
    info(title, text = '') {
        return customSwal.fire({
            icon: 'info',
            title,
            text,
            iconColor: '#6366f1', // indigo-500
        });
    },

    /**
     * Show a confirmation modal (returns Promise<boolean>)
     */
    async confirm(title, text = '', confirmButtonText = 'Yes, Proceed', cancelButtonText = 'Cancel', isDestructive = false) {
        const result = await customSwal.fire({
            title,
            text,
            icon: isDestructive ? 'warning' : 'question',
            iconColor: isDestructive ? '#f43f5e' : '#6366f1',
            showCancelButton: true,
            confirmButtonText,
            cancelButtonText,
            reverseButtons: true,
            confirmButtonColor: isDestructive ? '#e11d48' : '#4f46e5', // rose-600 vs indigo-600
        });

        return result.isConfirmed;
    },

    /**
     * Show a quick toast notification
     */
    toast(title, icon = 'success') {
        return Toast.fire({
            icon,
            title,
        });
    }
};

export default notify;
