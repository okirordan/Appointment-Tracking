import type { ReactNode, SVGProps } from 'react';

export interface IconProps extends SVGProps<SVGSVGElement> {
    /** Rendered size in px. Stylesheet width/height rules still win. */
    size?: number | string;
}

export type IconComponent = (props: IconProps) => ReactNode;

/**
 * Wraps Airaa Design outline glyphs in a shared 24px frame. Stroke colour is
 * inherited so icons pick up the surrounding text colour, which is what every
 * existing `.… svg { color: … }` rule in app.css relies on.
 */
export function createIcon(displayName: string, children: ReactNode): IconComponent {
    const Icon = ({ size = 24, ...props }: IconProps) => (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.5}
            strokeLinecap="round"
            strokeLinejoin="round"
            {...props}
        >
            {children}
        </svg>
    );

    Icon.displayName = displayName;

    return Icon;
}
