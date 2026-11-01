import type { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement> & { size?: number; strokeWidth?: number };
function IconFrame({ size = 22, strokeWidth = 1.8, children, ...props }: IconProps) {
  return <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round" {...props}>{children}</svg>;
}

// Navigation-only variants approved in the isolated icon preview.
export function OverviewIcon(props: IconProps) {
  return <IconFrame {...props}><rect x="3" y="3" width="7" height="7" rx="2" fill="currentColor" stroke="none"/><rect x="14" y="3" width="7" height="7" rx="2" fill="currentColor" stroke="none" opacity=".55"/><rect x="3" y="14" width="7" height="7" rx="2" fill="currentColor" stroke="none" opacity=".55"/><rect x="14" y="14" width="7" height="7" rx="2" fill="currentColor" stroke="none"/></IconFrame>;
}
export function PeopleIcon(props: IconProps) {
  return <IconFrame {...props}><circle cx="9" cy="7" r="4"/><path d="M2 21c0-4.4 3.1-7 7-7s7 2.6 7 7M16 3.5a4 4 0 0 1 0 7.5M18 14.5c2.5 1 4 3.2 4 6.5"/></IconFrame>;
}
export function ReceiptIcon(props: IconProps) {
  return <IconFrame {...props}><path d="M5 2h14v20l-3-2-4 2-4-2-3 2V2Z"/><path d="M8 7h8M8 11h8M8 15h5"/></IconFrame>;
}
export function ReportsIcon(props: IconProps) {
  return <IconFrame {...props}><path d="M3 20h18M3 14l6-6 4 4 8-9M15 3h6v6"/></IconFrame>;
}
