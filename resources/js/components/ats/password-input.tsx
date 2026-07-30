import { Eye, EyeOff } from 'lucide-react';
import { forwardRef, useState, type InputHTMLAttributes } from 'react';

type PasswordInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>;

const PasswordInput = forwardRef<HTMLInputElement, PasswordInputProps>(function PasswordInput({ className, disabled, ...props }, ref) {
    const [visible, setVisible] = useState(false);
    const label = visible ? 'Hide password' : 'Show password';

    return (
        <div className="password-control">
            <input {...props} ref={ref} className={className} disabled={disabled} type={visible ? 'text' : 'password'} />
            <button
                type="button"
                className="password-toggle"
                aria-label={label}
                aria-pressed={visible}
                title={label}
                disabled={disabled}
                onClick={() => setVisible((current) => !current)}
            >
                {visible ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
            </button>
        </div>
    );
});

export default PasswordInput;
