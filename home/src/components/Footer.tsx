import React from 'react';
export function Footer() {
  return (
    <footer className="bg-forest-dark text-cream/80 py-12 border-t border-forest-light/30">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-8">
          <div className="col-span-1 md:col-span-2">
            <a
              href="#"
              className="text-2xl font-bold text-cream tracking-tight mb-4 block">
              
              Rezervacije<span className="text-terracotta">.</span>
            </a>
            <p className="text-sm max-w-sm text-cream/60">
              A simple, modern reservation management system built for
              restaurants — from solo locations to multi-venue chains.
            </p>
          </div>

          <div>
            <h4 className="text-cream font-semibold mb-4">Product</h4>
            <ul className="space-y-2 text-sm">
              <li>
                <a
                  href="#features"
                  className="hover:text-terracotta transition-colors">
                  
                  Features
                </a>
              </li>
              <li>
                <a
                  href="#pricing"
                  className="hover:text-terracotta transition-colors">
                  
                  Pricing
                </a>
              </li>
              <li>
                <a
                  href="#faq"
                  className="hover:text-terracotta transition-colors">
                  
                  FAQ
                </a>
              </li>
            </ul>
          </div>

          <div>
            <h4 className="text-cream font-semibold mb-4">Legal</h4>
            <ul className="space-y-2 text-sm">
              <li>
                <a href="#" className="hover:text-terracotta transition-colors">
                  Contact Us
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-terracotta transition-colors">
                  Privacy Policy
                </a>
              </li>
              <li>
                <a href="#" className="hover:text-terracotta transition-colors">
                  Terms of Service
                </a>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-12 pt-8 border-t border-forest-light/30 text-sm text-cream/50 flex flex-col md:flex-row justify-between items-center">
          <p>
            &copy; {new Date().getFullYear()} Rezervacije. All rights reserved.
          </p>
        </div>
      </div>
    </footer>);

}